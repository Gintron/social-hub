<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Notifications\AccountNeedsReconnect;
use App\Publishing\TikTok\TikTokTokens;
use App\Support\AdminNotifier;
use Illuminate\Console\Command;
use Throwable;

final class RefreshTikTokTokens extends Command
{
    /**
     * Refresh well before the 24-hour expiry so a scheduled post never meets a dead token.
     */
    private const RENEW_WITHIN_HOURS = 6;

    protected $signature = 'hub:refresh-tiktok-tokens {--all : Refresh every account, not only the ones near expiry}';

    protected $description = 'Trade TikTok refresh tokens for fresh access tokens before they expire';

    public function handle(TikTokTokens $oauth, AdminNotifier $notifier): int
    {
        $accounts = SocialAccount::query()
            ->with('brand')
            ->where('platform', Platform::TikTok->value)
            ->whereNotNull('refresh_token')
            ->where('status', '!=', AccountStatus::Disabled->value)
            ->when(! $this->option('all'), fn ($query) => $query->where(function ($q): void {
                $q->whereNull('token_expires_at')->orWhere('token_expires_at', '<=', now()->addHours(self::RENEW_WITHIN_HOURS));
            }))
            ->get();

        if ($accounts->isEmpty()) {
            $this->line('Nema TikTok računa kojima token uskoro istječe.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $token = $oauth->refresh((string) $account->refresh_token);
            } catch (Throwable $e) {
                $failed++;
                $account->forceFill(['status' => AccountStatus::NeedsReconnect])->save();
                $notifier->notify(new AccountNeedsReconnect($account, "Osvježavanje TikTok tokena nije uspjelo: {$e->getMessage()}"));
                $this->error("{$account->name}: {$e->getMessage()}");

                continue;
            }

            $account->forceFill([
                'access_token' => $token['access_token'],
                // TikTok hands back a new refresh token each time; keeping the old one would work
                // until it silently does not.
                'refresh_token' => $token['refresh_token'] !== '' ? $token['refresh_token'] : $account->refresh_token,
                'token_expires_at' => $token['expires_at'],
                'refresh_token_expires_at' => $token['refresh_expires_at'] ?? $account->refresh_token_expires_at,
                'status' => AccountStatus::Active,
                'last_verified_at' => now(),
            ])->save();

            $this->line("<info>OK</info>  {$account->name} — vrijedi do ".$token['expires_at']->timezone(config('hub.brand_default_timezone'))->format('d.m.Y H:i'));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
