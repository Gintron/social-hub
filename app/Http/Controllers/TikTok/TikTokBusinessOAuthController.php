<?php

declare(strict_types=1);

namespace App\Http\Controllers\TikTok;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Publishing\TikTok\Business\TikTokBusinessClient;
use App\Publishing\TikTok\Business\TikTokBusinessOAuth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects a brand's TikTok account through TikTok API for Business.
 *
 * Same shape as the developer flow. The portal-generated authorize URL is TikTok's standard
 * `/v2/auth/authorize` with `response_type=code`, so the callback carries `code`; the token call
 * then sends that value as `auth_code`. `auth_code` is still read in case TikTok ever echoes the
 * body name. The redirect URL must match the portal character for character — including the
 * trailing slash it insists on.
 */
final class TikTokBusinessOAuthController extends Controller
{
    private const STATE_KEY = 'tiktok_business_oauth';

    public function __construct(private readonly TikTokBusinessOAuth $oauth) {}

    public function connect(Request $request, Brand $brand): RedirectResponse
    {
        $state = Str::random(40);

        $request->session()->put(self::STATE_KEY, ['state' => $state, 'brand_id' => $brand->id]);

        return redirect()->away($this->oauth->authorizeUrl($state));
    }

    public function callback(Request $request, TikTokBusinessClient $client): RedirectResponse
    {
        $expected = (array) $request->session()->pull(self::STATE_KEY, []);
        $back = redirect()->to(SocialAccountResource::getUrl('index'));

        if ($request->filled('error')) {
            return $this->fail($back, 'Spajanje je prekinuto', (string) $request->query('error_description', (string) $request->query('error')));
        }

        if (blank($expected['state'] ?? null) || ! hash_equals((string) $expected['state'], (string) $request->query('state', ''))) {
            return $this->fail($back, 'Spajanje nije uspjelo', 'State se ne podudara — pokušaj ponovno iz istog preglednika.');
        }

        $brand = Brand::query()->find($expected['brand_id'] ?? 0);
        $code = (string) ($request->query('code') ?: $request->query('auth_code', ''));

        if ($brand === null || $code === '') {
            return $this->fail($back, 'Spajanje nije uspjelo', 'Nedostaje brend ili kod koji TikTok vraća.');
        }

        try {
            $token = $this->oauth->exchangeCode($code);
        } catch (Throwable $e) {
            return $this->fail($back, 'Spajanje nije uspjelo', $e->getMessage());
        }

        $profile = $this->profile($client, $token['access_token'], $token['open_id']);
        $username = (string) ($profile['username'] ?? $profile['display_name'] ?? $token['open_id']);

        // TikTok skips the consent screen for an account that already authorized the app, so a
        // browser still logged in as one brand's account comes back with that account whatever
        // brand was picked. Moving it silently would send one brand's posts to another's feed.
        $existing = SocialAccount::query()
            ->with('brand')
            ->where('platform', Platform::TikTok->value)
            ->where('external_id', $token['open_id'])
            ->first();

        if ($existing !== null && $existing->brand_id !== $brand->id) {
            return $this->fail(
                $back,
                "@{$username} je već povezan s brendom {$existing->brand?->name}",
                "TikTok je vratio račun u koji je preglednik prijavljen. Za {$brand->name} se odjavi na tiktok.com (ili otvori privatni prozor) i prijavi u TikTok račun tog brenda, pa pokušaj ponovno.",
            );
        }

        $account = SocialAccount::query()->updateOrCreate(
            ['platform' => Platform::TikTok->value, 'external_id' => $token['open_id']],
            [
                'brand_id' => $brand->id,
                'name' => '@'.$username,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'token_expires_at' => $token['expires_at'],
                'refresh_token_expires_at' => $token['refresh_expires_at'],
                'status' => AccountStatus::Active,
                'last_verified_at' => now(),
                'meta' => [
                    // Publishing sends this back as `business_id`; without it the account cannot post.
                    'open_id' => $token['open_id'],
                    'username' => $username,
                    'scope' => $token['scope'],
                    'api' => 'business',
                ],
            ],
        );

        Notification::make()
            ->title("TikTok račun {$account->name} povezan")
            ->body('Dozvole: '.($token['scope'] !== '' ? $token['scope'] : 'nije prijavljeno.'))
            ->success()
            ->send();

        return $back;
    }

    /**
     * Cosmetic: the account is usable without a display name, so a failure here only costs the
     * panel a pretty label. The endpoint is unverified until the app is live, hence the catch.
     *
     * @return array<string, mixed>
     */
    private function profile(TikTokBusinessClient $client, string $token, string $businessId): array
    {
        try {
            return $client->get('business/get/', [
                'business_id' => $businessId,
                'fields' => json_encode(['username', 'display_name']),
            ], $token, 'tiktok.connect');
        } catch (Throwable $e) {
            Log::warning('hub.tiktok.business_profile_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    private function fail(RedirectResponse $response, string $title, string $body): RedirectResponse
    {
        Notification::make()->title($title)->body($body)->danger()->persistent()->send();

        return $response;
    }
}
