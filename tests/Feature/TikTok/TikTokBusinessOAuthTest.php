<?php

declare(strict_types=1);

namespace Tests\Feature\TikTok;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Filament\Resources\SocialAccounts\Pages\ListSocialAccounts;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Publishing\TikTok\Business\TikTokBusinessOAuth;
use App\Publishing\TikTok\TikTokTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class TikTokBusinessOAuthTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tiktok.driver', 'business');
        config()->set('tiktok.business.app_id', 'app-id');
        config()->set('tiktok.business.app_secret', 'app-secret');
        config()->set('tiktok.business.redirect', 'https://hub.test/tiktok/business/callback/');
        config()->set('tiktok.business.authorize_url', 'https://www.tiktok.com/v2/auth/authorize?client_key=app-id&response_type=code');

        $this->brand = Brand::factory()->create(['name' => 'Studentski poslovi']);
        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    public function test_the_authorize_url_is_the_one_tiktok_generated_plus_state(): void
    {
        $response = $this->get(route('tiktok.business.connect', $this->brand));

        $response->assertRedirectContains('https://www.tiktok.com/v2/auth/authorize?client_key=app-id&response_type=code');
        $response->assertRedirectContains('state=');

        $this->assertSame($this->brand->id, session('tiktok_business_oauth')['brand_id']);
    }

    public function test_the_callback_exchanges_the_code_and_stores_the_open_id_for_publishing(): void
    {
        $this->fakeTokens();

        $this->withSession(['tiktok_business_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/tiktok/business/callback/?code=the-code&state=st-1')
            ->assertRedirect();

        $account = SocialAccount::query()->where('platform', Platform::TikTok->value)->firstOrFail();

        $this->assertSame('open-id-9', $account->external_id);
        // Publishing sends this back as business_id, so it has to survive the connect.
        $this->assertSame('open-id-9', $account->meta['open_id']);
        $this->assertSame('business', $account->meta['api']);
        $this->assertSame('@brandhr', $account->name);
        $this->assertSame('access-9', $account->access_token);
        $this->assertSame('refresh-9', $account->refresh_token);
        $this->assertSame(AccountStatus::Active, $account->status);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/tt_user/oauth2/token/')) {
                return false;
            }

            $body = $request->data();

            // TikTok rejects the exchange unless the redirect matches the app exactly.
            return $body['auth_code'] === 'the-code'
                && $body['grant_type'] === 'authorization_code'
                && $body['redirect_uri'] === 'https://hub.test/tiktok/business/callback/'
                && $body['client_id'] === 'app-id';
        });
    }

    public function test_a_callback_that_names_it_auth_code_still_connects(): void
    {
        $this->fakeTokens();

        $this->withSession(['tiktok_business_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/tiktok/business/callback/?auth_code=the-code&state=st-1')
            ->assertRedirect();

        $this->assertSame(1, SocialAccount::query()->where('platform', Platform::TikTok->value)->count());
    }

    public function test_the_panel_connects_through_the_business_flow_when_it_is_the_driver(): void
    {
        // The developer key is deliberately absent: the button must not depend on it any more.
        config()->set('tiktok.client_key', null);

        Livewire::test(ListSocialAccounts::class)
            ->assertActionVisible('connect_tiktok')
            ->callAction('connect_tiktok', data: ['brand_id' => $this->brand->id])
            ->assertRedirect(route('tiktok.business.connect', $this->brand));
    }

    public function test_a_mismatched_state_never_exchanges_the_code(): void
    {
        $this->fakeTokens();

        $this->withSession(['tiktok_business_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/tiktok/business/callback/?code=the-code&state=forged')
            ->assertRedirect();

        $this->assertSame(0, SocialAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_connect_failure_arrives_as_http_200_with_a_non_zero_code(): void
    {
        Http::fake([
            '*/tt_user/oauth2/token/' => Http::response(['code' => 40002, 'message' => 'auth_code expired']),
        ]);

        $this->withSession(['tiktok_business_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/tiktok/business/callback/?code=stale&state=st-1')
            ->assertRedirect();

        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_the_refresh_command_follows_the_configured_driver(): void
    {
        $this->assertInstanceOf(TikTokBusinessOAuth::class, app(TikTokTokens::class));

        Http::fake([
            '*/tt_user/oauth2/refresh_token/' => Http::response([
                'code' => 0,
                'data' => [
                    'access_token' => 'access-new',
                    'refresh_token' => 'refresh-new',
                    'open_id' => 'open-id-9',
                    'expires_in' => 86400,
                    'refresh_token_expires_in' => 31536000,
                    'scope' => 'video.publish',
                ],
            ]),
        ]);

        $account = SocialAccount::factory()->for($this->brand)->create([
            'platform' => Platform::TikTok,
            'access_token' => 'access-old',
            'refresh_token' => 'refresh-old',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->artisan('hub:refresh-tiktok-tokens')->assertSuccessful();

        $account->refresh();

        $this->assertSame('access-new', $account->access_token);
        // TikTok hands back a renewed refresh token; keeping the old one works until it does not.
        $this->assertSame('refresh-new', $account->refresh_token);
    }

    private function fakeTokens(): void
    {
        Http::fake([
            '*/tt_user/oauth2/token/' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'data' => [
                    'access_token' => 'access-9',
                    'refresh_token' => 'refresh-9',
                    'open_id' => 'open-id-9',
                    'expires_in' => 86400,
                    'refresh_token_expires_in' => 31536000,
                    'scope' => 'video.publish,video.upload',
                ],
            ]),
            '*/business/get/*' => Http::response([
                'code' => 0,
                'data' => ['username' => 'brandhr', 'display_name' => 'Brand'],
            ]),
        ]);
    }
}
