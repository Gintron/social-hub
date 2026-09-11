<?php

declare(strict_types=1);

namespace Tests\Feature\TikTok;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\AccountNeedsReconnect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class TikTokOAuthTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tiktok.client_key', 'client-key');
        config()->set('tiktok.client_secret', 'client-secret');
        config()->set('tiktok.redirect', 'https://hub.test/tiktok/callback');
        config()->set('hub.admin_emails', ['ops@example.test']);

        $this->brand = Brand::factory()->create(['name' => 'Studentski poslovi']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    public function test_connect_sends_the_admin_to_tiktok_with_the_publish_scope(): void
    {
        $response = $this->get(route('tiktok.connect', $this->brand));

        $response->assertRedirectContains('https://www.tiktok.com/v2/auth/authorize/');
        $response->assertRedirectContains('client_key=client-key');
        $response->assertRedirectContains(urlencode('video.publish'));
        $response->assertRedirectContains(urlencode('https://hub.test/tiktok/callback'));

        $this->assertSame($this->brand->id, session('tiktok_oauth')['brand_id']);
    }

    public function test_the_callback_stores_both_tokens_and_what_the_creator_allows(): void
    {
        $this->fakeTikTok();

        $this->withSession(['tiktok_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get(route('tiktok.callback', ['code' => 'auth-code', 'state' => 'st-1']))
            ->assertRedirect();

        $account = SocialAccount::query()->where('platform', Platform::TikTok->value)->firstOrFail();

        $this->assertSame('@studentskiposlovi', $account->name);
        $this->assertSame('open-id-1', $account->external_id);
        $this->assertSame('access-1', $account->access_token);
        $this->assertSame('refresh-1', $account->refresh_token);
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertTrue($account->token_expires_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertSame(['SELF_ONLY'], $account->meta['privacy_level_options']);

        // The refresh token is a credential: the row itself must not hold it in the clear.
        // Read through the query builder, which bypasses the model's casts.
        $stored = (string) DB::table('social_accounts')->where('id', $account->id)->value('refresh_token');
        $this->assertNotSame('refresh-1', $stored);
        $this->assertStringNotContainsString('refresh-1', $stored);
    }

    public function test_the_creator_info_request_sends_an_empty_object_not_an_empty_array(): void
    {
        $this->fakeTikTok();

        $this->withSession(['tiktok_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get(route('tiktok.callback', ['code' => 'auth-code', 'state' => 'st-1']));

        // TikTok rejected a bare `[]` body with "invalid_params: The request parameter type is
        // incorrect" — json_encode() can't tell an empty array from an empty object, and always
        // picks array. An empty payload must be cast to an object before it reaches the wire.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'creator_info/query')
            && $request->body() === '{}');
    }

    public function test_a_mismatched_state_stores_nothing(): void
    {
        Http::fake();

        $this->withSession(['tiktok_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get(route('tiktok.callback', ['code' => 'auth-code', 'state' => 'forged']))
            ->assertRedirect();

        $this->assertSame(0, SocialAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_refresh_rotates_both_tokens_before_expiry(): void
    {
        Http::fake(fn () => Http::response([
            'access_token' => 'access-2',
            'refresh_token' => 'refresh-2',
            'open_id' => 'open-id-1',
            'expires_in' => 86400,
            'refresh_expires_in' => 31536000,
            'scope' => 'video.publish',
        ]));

        $account = $this->account(['token_expires_at' => now()->addHours(2)]);

        $this->artisan('hub:refresh-tiktok-tokens')->assertSuccessful();

        $account->refresh();
        $this->assertSame('access-2', $account->access_token);
        // TikTok issues a new refresh token every time; keeping the old one works until it does not.
        $this->assertSame('refresh-2', $account->refresh_token);
        $this->assertTrue($account->token_expires_at->isAfter(now()->addHours(20)));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/oauth/token/')
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-1');
    }

    public function test_an_account_far_from_expiry_is_left_alone(): void
    {
        Http::fake();

        $this->account(['token_expires_at' => now()->addHours(20)]);

        $this->artisan('hub:refresh-tiktok-tokens')
            ->expectsOutputToContain('Nema TikTok računa')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_dead_refresh_token_marks_the_account_and_tells_someone(): void
    {
        Notification::fake();
        Http::fake(fn () => Http::response(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid'], 400));

        $account = $this->account(['token_expires_at' => now()->addHour()]);

        $this->artisan('hub:refresh-tiktok-tokens')->assertFailed();

        $this->assertSame(AccountStatus::NeedsReconnect, $account->refresh()->status);
        Notification::assertSentOnDemand(AccountNeedsReconnect::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $attributes = []): SocialAccount
    {
        return SocialAccount::factory()->for($this->brand)->create(array_replace([
            'platform' => Platform::TikTok,
            'name' => '@studentskiposlovi',
            'external_id' => 'open-id-1',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
        ], $attributes));
    }

    private function fakeTikTok(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/oauth/token/')) {
                return Http::response([
                    'access_token' => 'access-1',
                    'refresh_token' => 'refresh-1',
                    'open_id' => 'open-id-1',
                    'expires_in' => 86400,
                    'refresh_expires_in' => 31536000,
                    'scope' => 'user.info.basic,video.publish',
                ]);
            }

            return Http::response([
                'data' => [
                    'creator_username' => 'studentskiposlovi',
                    'privacy_level_options' => ['SELF_ONLY'],
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ]);
        });
    }
}
