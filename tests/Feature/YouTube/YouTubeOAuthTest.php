<?php

declare(strict_types=1);

namespace Tests\Feature\YouTube;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class YouTubeOAuthTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('youtube.client_id', 'client-id');
        config()->set('youtube.client_secret', 'client-secret');
        config()->set('youtube.redirect', 'https://hub.test/youtube/callback');

        $this->brand = Brand::factory()->create(['name' => 'Studentski poslovi']);
        $this->actingAs(User::factory()->create());
    }

    public function test_connect_sends_the_brand_to_google_for_offline_access_with_consent(): void
    {
        $response = $this->get(route('youtube.connect', $this->brand));

        $response->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth?');
        // offline + consent is what makes Google hand back a refresh token on every connect.
        $response->assertRedirectContains('access_type=offline');
        $response->assertRedirectContains('prompt=consent');
        $response->assertRedirectContains('include_granted_scopes=true');
        $response->assertRedirectContains('youtube.upload');
        $response->assertRedirectContains('youtube.readonly');
        $response->assertRedirectContains('redirect_uri='.urlencode('https://hub.test/youtube/callback'));
        $response->assertRedirectContains('state=');

        $this->assertSame($this->brand->id, session('youtube_oauth')['brand_id']);
    }

    public function test_the_callback_connects_the_channel_the_grant_names(): void
    {
        $this->fakeGoogle();

        $this->withSession(['youtube_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/youtube/callback?code=the-code&state=st-1')
            ->assertRedirect();

        $account = SocialAccount::query()->where('platform', Platform::YouTube->value)->firstOrFail();

        $this->assertSame($this->brand->id, $account->brand_id);
        $this->assertSame('UC123', $account->external_id);
        $this->assertSame('Studentski poslovi', $account->name);
        $this->assertSame('access-1', $account->access_token);
        $this->assertSame('refresh-1', $account->refresh_token);
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertSame('UC123', $account->meta['channel_id']);
        $this->assertSame('@studentski', $account->meta['handle']);
        $this->assertSame('youtube', $account->meta['api']);
        $this->assertTrue($account->token_expires_at->isFuture());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && ($request->data()['grant_type'] ?? null) === 'authorization_code'
            && ($request->data()['code'] ?? null) === 'the-code'
            && ($request->data()['redirect_uri'] ?? null) === 'https://hub.test/youtube/callback'
            && ($request->data()['client_id'] ?? null) === 'client-id');

        // Only the channels the grant covers are asked for: mine=true.
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/channels')
            && str_contains($request->url(), 'mine=true'));
    }

    public function test_a_callback_whose_state_does_not_match_connects_nothing(): void
    {
        $this->fakeGoogle();

        $this->withSession(['youtube_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/youtube/callback?code=the-code&state=other')
            ->assertRedirect();

        $this->assertDatabaseMissing('social_accounts', ['platform' => Platform::YouTube->value]);
        Http::assertNothingSent();
    }

    public function test_a_declined_consent_connects_nothing(): void
    {
        $this->withSession(['youtube_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/youtube/callback?error=access_denied&state=st-1')
            ->assertRedirect();

        $this->assertDatabaseMissing('social_accounts', ['platform' => Platform::YouTube->value]);
    }

    public function test_a_channel_already_connected_to_another_brand_is_not_moved(): void
    {
        $this->fakeGoogle();

        $other = Brand::factory()->create(['name' => 'Radim']);
        $existing = SocialAccount::factory()->for($other)->create([
            'platform' => Platform::YouTube,
            'external_id' => 'UC123',
            'name' => 'Studentski poslovi',
        ]);

        $this->withSession(['youtube_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/youtube/callback?code=the-code&state=st-1')
            ->assertRedirect();

        $this->assertSame($other->id, $existing->fresh()->brand_id);
        $this->assertSame(1, SocialAccount::query()->where('platform', Platform::YouTube->value)->count());
    }

    public function test_a_renewal_without_a_new_refresh_token_keeps_the_stored_one(): void
    {
        $this->fakeGoogle(refreshToken: null);

        $existing = SocialAccount::factory()->for($this->brand)->create([
            'platform' => Platform::YouTube,
            'external_id' => 'UC123',
            'refresh_token' => 'refresh-old',
        ]);

        $this->withSession(['youtube_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/youtube/callback?code=the-code&state=st-1')
            ->assertRedirect();

        $existing->refresh();

        $this->assertSame('refresh-old', $existing->refresh_token);
        $this->assertSame('access-1', $existing->access_token);
    }

    public function test_a_first_connection_without_a_refresh_token_is_refused(): void
    {
        $this->fakeGoogle(refreshToken: null);

        $this->withSession(['youtube_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/youtube/callback?code=the-code&state=st-1')
            ->assertRedirect();

        $this->assertDatabaseMissing('social_accounts', ['platform' => Platform::YouTube->value]);
    }

    public function test_a_google_account_without_a_channel_connects_nothing(): void
    {
        $this->fakeGoogle(channels: []);

        $this->withSession(['youtube_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get('/youtube/callback?code=the-code&state=st-1')
            ->assertRedirect();

        $this->assertDatabaseMissing('social_accounts', ['platform' => Platform::YouTube->value]);
    }

    /**
     * @param  list<array<string, mixed>>  $channels
     */
    private function fakeGoogle(?string $refreshToken = 'refresh-1', array $channels = [['id' => 'UC123', 'snippet' => ['title' => 'Studentski poslovi', 'customUrl' => '@studentski']]]): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(array_filter([
                'access_token' => 'access-1',
                'refresh_token' => $refreshToken,
                'expires_in' => 3599,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
                'token_type' => 'Bearer',
            ], fn (mixed $value): bool => $value !== null), 200),
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => $channels], 200),
        ]);
    }
}
