<?php

declare(strict_types=1);

namespace Tests\Feature\TikTok;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Filament\Resources\SocialAccounts\Pages\ListSocialAccounts;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class TikTokAccountVerificationTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $tiktok;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tiktok.driver', 'business');
        config()->set('hub.admin_emails', ['ops@example.test']);

        $this->tiktok = SocialAccount::factory()->create([
            'platform' => Platform::TikTok,
            'name' => '@listo_hrvatska',
            'external_id' => 'open-id-9',
            'access_token' => 'tt-token',
            'meta' => ['open_id' => 'open-id-9', 'api' => 'business'],
        ]);
    }

    public function test_the_morning_check_never_sends_a_tiktok_token_to_graph(): void
    {
        // Graph rejects anything that is not a Meta token exactly like this.
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 400)]);

        $this->artisan('hub:verify-accounts');

        $this->assertSame(AccountStatus::Active, $this->tiktok->refresh()->status);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'open-id-9'));
    }

    public function test_the_morning_check_still_covers_meta_accounts(): void
    {
        $page = SocialAccount::factory()->create();
        Http::fake(['*' => Http::response(['id' => $page->external_id, 'name' => 'Page'])]);

        $this->artisan('hub:verify-accounts')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $page->external_id));
    }

    public function test_the_verify_button_asks_tiktok_about_a_tiktok_account(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
        Http::fake(['*/business/get/*' => Http::response(['code' => 0, 'data' => ['username' => 'listo_hrvatska']])]);

        Livewire::test(ListSocialAccounts::class)->callTableAction('verify', $this->tiktok);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/business/get/')
            && $request['business_id'] === 'open-id-9'
            && $request->header('Access-Token') === ['tt-token']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com'));
        $this->assertSame(AccountStatus::Active, $this->tiktok->refresh()->status);
    }
}
