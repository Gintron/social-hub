<?php

declare(strict_types=1);

namespace Tests\Feature\TikTok;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RefreshTikTokTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_accounts_rotate_tokens_with_their_issuing_api_regardless_of_global_driver(): void
    {
        config()->set('tiktok.client_key', 'developer-key');
        config()->set('tiktok.client_secret', 'developer-secret');
        config()->set('tiktok.business.app_id', 'business-id');
        config()->set('tiktok.business.app_secret', 'business-secret');
        config()->set('tiktok.business.redirect', 'https://example.test/tiktok/business/callback/');
        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'access_token' => 'developer-access', 'refresh_token' => 'developer-refresh',
                'expires_in' => 86400, 'refresh_expires_in' => 31536000,
            ]),
            'business-api.tiktok.com/open_api/v1.3/tt_user/oauth2/refresh_token/' => Http::response([
                'code' => 0,
                'data' => [
                    'access_token' => 'business-access', 'refresh_token' => 'business-refresh',
                    'expires_in' => 86400, 'refresh_token_expires_in' => 31536000,
                ],
            ]),
        ]);
        $accounts = collect(['business', 'developer', null])->map(fn (?string $api): SocialAccount => SocialAccount::factory()->create([
            'platform' => Platform::TikTok,
            'meta' => $api === null ? null : ['api' => $api],
            'refresh_token' => 'old-'.($api ?? 'legacy'),
            'token_expires_at' => now()->addHour(),
        ]));

        foreach (['business', 'developer'] as $driver) {
            config()->set('tiktok.driver', $driver);
            $this->artisan('hub:refresh-tiktok-tokens', ['--all' => true])->assertSuccessful();
            foreach ($accounts as $account) {
                $account->refresh();
                $issuer = data_get($account->meta, 'api') === 'business' ? 'business' : 'developer';
                $this->assertSame($issuer.'-access', $account->access_token);
                $this->assertSame($issuer.'-refresh', $account->refresh_token);
                $this->assertSame(AccountStatus::Active, $account->status);
            }
        }

        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'open.tiktokapis.com') && $request['refresh_token'] === 'old-legacy');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'business-api.tiktok.com') && $request['refresh_token'] === 'old-business');
    }
}
