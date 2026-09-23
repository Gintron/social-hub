<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Notifications\AccountNeedsReconnect;
use App\Publishing\Exceptions\TokenInvalidException;
use App\Publishing\Meta\GraphClient;
use App\Support\AdminNotifier;
use Illuminate\Console\Command;
use Throwable;

final class VerifyAccounts extends Command
{
    protected $signature = 'hub:verify-accounts';

    protected $description = 'Ping every connected Meta account so a dead token is noticed before a scheduled post fails';

    public function handle(GraphClient $graph, AdminNotifier $notifier): int
    {
        // Graph API only knows Meta tokens. A TikTok token sent here comes back "invalid OAuth
        // token", which used to mark every TikTok account for reconnection each morning; TikTok
        // tokens are proven hourly by hub:refresh-tiktok-tokens instead.
        $accounts = SocialAccount::query()->with('brand')->active()
            ->whereIn('platform', [Platform::FacebookPage->value, Platform::InstagramBusiness->value])
            ->get();

        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $graph->get($account->external_id, ['fields' => 'id,name'], (string) $account->access_token, 'verify');
                $account->forceFill(['last_verified_at' => now()])->save();
                $this->line("<info>OK</info>  {$account->platform->label()} · {$account->name}");
            } catch (TokenInvalidException $e) {
                $failed++;
                $account->forceFill(['status' => AccountStatus::NeedsReconnect])->save();
                $notifier->notify(new AccountNeedsReconnect($account, $e->getMessage()));
                $this->error("TOKEN {$account->platform->label()} · {$account->name}: {$e->getMessage()}");
            } catch (Throwable $e) {
                $this->warn("WARN {$account->platform->label()} · {$account->name}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
