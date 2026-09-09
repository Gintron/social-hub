<?php

declare(strict_types=1);

namespace Tests\Feature\Meta;

use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class MetaOAuthTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_id', '123456');
        config()->set('meta.app_secret', 'app-secret');
        config()->set('meta.graph_version', 'v23.0');
        config()->set('meta.redirect', 'https://hub.test/meta/callback');
        config()->set('hub.admin_emails', ['ops@example.test']);

        $this->brand = Brand::factory()->create(['name' => 'Studentski poslovi']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    public function test_connect_redirects_to_the_consent_dialog_and_remembers_the_state(): void
    {
        config()->set('meta.login_config_id', 'cfg-9');

        $response = $this->get(route('meta.connect', $this->brand));

        $response->assertRedirectContains('https://www.facebook.com/v23.0/dialog/oauth');
        $response->assertRedirectContains('client_id=123456');
        $response->assertRedirectContains('config_id=cfg-9');
        $response->assertRedirectContains(urlencode('https://hub.test/meta/callback'));

        $state = session('meta_oauth');
        $this->assertSame($this->brand->id, $state['brand_id']);
        $this->assertNotEmpty($state['state']);
        $response->assertRedirectContains('state='.$state['state']);
    }

    public function test_dialog_falls_back_to_an_explicit_scope_list(): void
    {
        config()->set('meta.login_config_id', null);

        $this->get(route('meta.connect', $this->brand))
            ->assertRedirectContains(urlencode('pages_manage_posts'))
            ->assertRedirectContains(urlencode('instagram_content_publish'));
    }

    public function test_callback_stores_pages_and_linked_instagram_accounts(): void
    {
        $this->fakeTokenAndAssets();

        $response = $this->withSession(['meta_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get(route('meta.callback', ['code' => 'auth-code', 'state' => 'st-1']));

        $response->assertRedirect();

        $page = SocialAccount::query()->where('platform', Platform::FacebookPage->value)->firstOrFail();
        $this->assertSame('Studentski poslovi HR', $page->name);
        $this->assertSame('page-1', $page->external_id);
        $this->assertSame('page-token-1', $page->access_token);
        $this->assertSame('fb-user-1', $page->meta['connected_user_id']);
        $this->assertSame($this->brand->id, $page->brand_id);

        $ig = SocialAccount::query()->where('platform', Platform::InstagramBusiness->value)->firstOrFail();
        $this->assertSame('@studentskiposlovi', $ig->name);
        $this->assertSame('ig-1', $ig->external_id);
        // Instagram publishing through Facebook login uses the Page token.
        $this->assertSame('page-token-1', $ig->access_token);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/oauth/access_token') && str_contains($r->url(), 'code=auth-code'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'grant_type=fb_exchange_token'));
    }

    public function test_callback_rejects_a_mismatched_state(): void
    {
        Http::fake();

        $this->withSession(['meta_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get(route('meta.callback', ['code' => 'auth-code', 'state' => 'forged']))
            ->assertRedirect();

        $this->assertSame(0, SocialAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_callback_handles_a_declined_dialog(): void
    {
        Http::fake();

        $this->withSession(['meta_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get(route('meta.callback', ['error' => 'access_denied', 'error_description' => 'Korisnik je odustao']))
            ->assertRedirect();

        $this->assertSame(0, SocialAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_callback_reports_a_token_that_manages_no_pages(): void
    {
        Http::fake([
            '*oauth/access_token*' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000]),
            '*/me?*' => Http::response(['id' => 'fb-user-1']),
            '*me/accounts*' => Http::response(['data' => []]),
        ]);

        $this->withSession(['meta_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get(route('meta.callback', ['code' => 'auth-code', 'state' => 'st-1']))
            ->assertRedirect();

        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_discovery_logs_what_facebook_returned(): void
    {
        Log::spy();

        Http::fake([
            '*oauth/access_token*' => Http::response(['access_token' => 'tok', 'expires_in' => 5184000]),
            '*/me?*' => Http::response(['id' => 'fb-user-1']),
            '*me/accounts*' => Http::response(['data' => []]),
        ]);

        $this->withSession(['meta_oauth' => ['state' => 'st-1', 'brand_id' => $this->brand->id]])
            ->get(route('meta.callback', ['code' => 'auth-code', 'state' => 'st-1']))
            ->assertRedirect();

        // Discovery is not tied to a post variant, so publish_logs never sees it; the log line is
        // the only record of why a connect attempt came back empty.
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === 'meta.discover'
                && $context['page_count'] === 0
                && $context['connected_user_id'] === 'fb-user-1'
        );
    }

    public function test_the_flow_is_closed_to_guests(): void
    {
        auth()->logout();

        $this->get(route('meta.connect', $this->brand))->assertRedirect(route('filament.admin.auth.login'));
        $this->get(route('meta.callback', ['code' => 'x']))->assertRedirect(route('filament.admin.auth.login'));
    }

    private function fakeTokenAndAssets(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response([
                    'access_token' => str_contains($url, 'fb_exchange_token') ? 'long-lived-token' : 'short-token',
                    'expires_in' => 5184000,
                ]);
            }

            if (str_contains($url, 'me/accounts')) {
                return Http::response(['data' => [[
                    'id' => 'page-1',
                    'name' => 'Studentski poslovi HR',
                    'access_token' => 'page-token-1',
                    'instagram_business_account' => ['id' => 'ig-1', 'username' => 'studentskiposlovi', 'name' => 'Studentski poslovi'],
                ]]]);
            }

            return Http::response(['id' => 'fb-user-1']);
        });
    }
}
