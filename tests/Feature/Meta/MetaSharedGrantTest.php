<?php

declare(strict_types=1);

namespace Tests\Feature\Meta;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Facebook keeps one grant per user and app: connecting one brand replaces the set of Pages the grant
 * covers, and the stored token of every Page left out dies with "(#190) … permission(s) must be
 * granted before impersonating a user's page". These tests pin how the hub answers that.
 */
final class MetaSharedGrantTest extends TestCase
{
    use RefreshDatabase;

    private Brand $studentski;

    private Brand $listo;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_id', '123456');
        config()->set('meta.app_secret', 'app-secret');
        config()->set('meta.graph_version', 'v23.0');
        config()->set('meta.redirect', 'https://hub.test/meta/callback');
        config()->set('hub.admin_emails', ['ops@example.test']);

        $this->studentski = Brand::factory()->create(['name' => 'Studentski poslovi']);
        $this->listo = Brand::factory()->create(['name' => 'Listo']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    public function test_one_login_refreshes_the_pages_of_every_brand_the_grant_covers(): void
    {
        $this->pageOf($this->studentski, 'page-s', 'old-s');
        $listoPage = $this->pageOf($this->listo, 'page-l', 'dead-l', AccountStatus::NeedsReconnect);
        $listoIg = $this->instagramOf($this->listo, 'ig-l', 'dead-l', AccountStatus::NeedsReconnect);

        $this->fakeGraph(grant: [
            'page-s' => ['token' => 'tok-s', 'ig' => 'ig-s'],
            'page-l' => ['token' => 'tok-l', 'ig' => 'ig-l'],
        ]);

        $this->connect($this->studentski);

        $this->assertSame('tok-s', SocialAccount::query()->where('external_id', 'page-s')->firstOrFail()->access_token);

        $listoPage->refresh();
        $listoIg->refresh();
        // The other brand's Page is healed by the same login, and stays filed under its own brand.
        $this->assertSame('tok-l', $listoPage->access_token);
        $this->assertSame('tok-l', $listoIg->access_token);
        $this->assertSame(AccountStatus::Active, $listoPage->status);
        $this->assertSame(AccountStatus::Active, $listoIg->status);
        $this->assertSame($this->listo->id, $listoPage->brand_id);
        $this->assertSame($this->listo->id, $listoIg->brand_id);
        $this->assertNull($this->titleOfLostAccessNotice());
    }

    public function test_a_brand_the_new_grant_leaves_out_is_flagged_at_once_and_reported(): void
    {
        $this->pageOf($this->studentski, 'page-s', 'old-s');
        $page = $this->pageOf($this->listo, 'page-l', 'old-l');
        $ig = $this->instagramOf($this->listo, 'ig-l', 'old-l');

        // The dialog was answered for Studentski poslovi only: Listo's Page is not in the grant, and
        // the token stored for it now fails the way Facebook fails it.
        $this->fakeGraph(grant: ['page-s' => ['token' => 'tok-s', 'ig' => 'ig-s']], dead: ['old-l']);

        $this->connect($this->studentski);

        $this->assertSame(AccountStatus::NeedsReconnect, $page->refresh()->status);
        $this->assertSame(AccountStatus::NeedsReconnect, $ig->refresh()->status);
        $this->assertSame(AccountStatus::Active, SocialAccount::query()->where('external_id', 'page-s')->firstOrFail()->status);

        $notice = collect(session('filament.notifications'))->firstWhere('title', 'Ovo povezivanje je oduzelo pristup drugim računima');
        $this->assertNotNull($notice);
        $this->assertStringContainsString('Listo - Popis', (string) $notice['body']);
        $this->assertStringContainsString('(Listo)', (string) $notice['body']);
    }

    public function test_a_brand_left_out_of_the_grant_is_flagged_even_when_the_login_connected_nothing(): void
    {
        $this->pageOf($this->studentski, 'page-s', 'old-s');
        $listoPage = $this->pageOf($this->listo, 'page-l', 'old-l');

        $this->fakeGraph(grant: [], dead: ['old-s', 'old-l']);

        $this->connect($this->studentski);

        $this->assertSame(AccountStatus::NeedsReconnect, $listoPage->refresh()->status);
        $this->assertNotNull($this->titleOfLostAccessNotice());
    }

    public function test_a_token_that_still_answers_is_not_flagged(): void
    {
        $this->pageOf($this->studentski, 'page-s', 'old-s');
        $page = $this->pageOf($this->listo, 'page-l', 'old-l');

        $this->fakeGraph(grant: ['page-s' => ['token' => 'tok-s', 'ig' => 'ig-s']]);

        $this->connect($this->studentski);

        // Not in the grant, but its token was asked and still works: nothing to report.
        $page->refresh();
        $this->assertSame(AccountStatus::Active, $page->status);
        $this->assertSame('old-l', $page->access_token);
        $this->assertNull($this->titleOfLostAccessNotice());
    }

    public function test_an_unclear_answer_from_graph_flags_nothing(): void
    {
        $this->pageOf($this->studentski, 'page-s', 'old-s');
        $page = $this->pageOf($this->listo, 'page-l', 'old-l');

        $this->fakeGraph(grant: ['page-s' => ['token' => 'tok-s', 'ig' => 'ig-s']], unreachable: ['old-l']);

        $this->connect($this->studentski);

        $this->assertSame(AccountStatus::Active, $page->refresh()->status);
        $this->assertNull($this->titleOfLostAccessNotice());
    }

    public function test_another_brands_login_does_not_revive_a_disabled_account(): void
    {
        // Data deletion switched it off and cleared the token; Studentski poslovi has no Page yet,
        // so the login takes whatever Facebook offers — except this one.
        $deleted = $this->pageOf($this->listo, 'page-l', null, AccountStatus::Disabled);

        $this->fakeGraph(grant: [
            'page-s' => ['token' => 'tok-s', 'ig' => 'ig-s'],
            'page-l' => ['token' => 'tok-l', 'ig' => 'ig-l'],
        ]);

        $this->connect($this->studentski);

        $deleted->refresh();
        $this->assertSame(AccountStatus::Disabled, $deleted->status);
        $this->assertNull($deleted->access_token);
        $this->assertSame(0, SocialAccount::query()->where('external_id', 'ig-l')->count());
        $this->assertSame($this->studentski->id, SocialAccount::query()->where('external_id', 'page-s')->firstOrFail()->brand_id);
    }

    public function test_a_brand_with_a_page_does_not_take_unknown_pages_from_the_grant(): void
    {
        $this->pageOf($this->studentski, 'page-s', 'old-s');

        $this->fakeGraph(grant: [
            'page-s' => ['token' => 'tok-s', 'ig' => 'ig-s'],
            'page-x' => ['token' => 'tok-x', 'ig' => null],
        ]);

        $this->connect($this->studentski);

        $this->assertSame(0, SocialAccount::query()->where('external_id', 'page-x')->count());
    }

    private function connect(Brand $brand): void
    {
        $this->withSession(['meta_oauth' => ['state' => 'st-1', 'brand_id' => $brand->id]])
            ->get(route('meta.callback', ['code' => 'auth-code', 'state' => 'st-1']))
            ->assertRedirect();
    }

    private function titleOfLostAccessNotice(): ?string
    {
        $notice = collect(session('filament.notifications', []))->firstWhere('title', 'Ovo povezivanje je oduzelo pristup drugim računima');

        return $notice['title'] ?? null;
    }

    private function pageOf(Brand $brand, string $id, ?string $token, AccountStatus $status = AccountStatus::Active): SocialAccount
    {
        return SocialAccount::factory()->create([
            'brand_id' => $brand->id,
            'platform' => Platform::FacebookPage,
            'external_id' => $id,
            'name' => $brand->id === $this->listo->id ? 'Listo - Popis' : 'Studentski poslovi',
            'access_token' => $token,
            'status' => $status,
        ]);
    }

    private function instagramOf(Brand $brand, string $id, ?string $token, AccountStatus $status = AccountStatus::Active): SocialAccount
    {
        return SocialAccount::factory()->instagram()->create([
            'brand_id' => $brand->id,
            'external_id' => $id,
            'access_token' => $token,
            'status' => $status,
        ]);
    }

    /**
     * A Facebook that answers like the real one: /me/accounts lists what the grant covers, a token that
     * fell out of the grant is refused with 190, and Pages outside the grant cannot be read by id.
     *
     * @param  array<string, array{token: string, ig: string|null}>  $grant
     * @param  list<string>  $dead  tokens Graph refuses as if the permission had been withdrawn
     * @param  list<string>  $unreachable  tokens whose check ends in a Graph outage
     */
    private function fakeGraph(array $grant, array $dead = [], array $unreachable = []): void
    {
        Http::fake(function (Request $request) use ($grant, $dead, $unreachable) {
            $url = $request->url();
            $token = (string) ($request->data()['access_token'] ?? '');

            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'user-token', 'expires_in' => 5184000]);
            }

            if (str_contains($url, 'me/accounts')) {
                return Http::response(['data' => collect($grant)->map(fn (array $page, string $id): array => [
                    'id' => $id,
                    'name' => 'Page '.$id,
                    'access_token' => $page['token'],
                    ...($page['ig'] !== null ? ['instagram_business_account' => ['id' => $page['ig'], 'username' => 'user_'.$page['ig'], 'name' => $page['ig']]] : []),
                ])->values()->all()]);
            }

            if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/me')) {
                return Http::response(['id' => 'fb-user-1']);
            }

            if (in_array($token, $dead, true)) {
                return Http::response(['error' => [
                    'message' => "Any of the pages_read_engagement, pages_manage_metadata, pages_show_list permission(s) must be granted before impersonating a user's page.",
                    'type' => 'OAuthException',
                    'code' => 190,
                ]], 400);
            }

            if (in_array($token, $unreachable, true)) {
                return Http::response(['error' => ['message' => 'An unknown error has occurred.', 'code' => 1]], 500);
            }

            // A Page asked for by id with the user token: only the ones in the grant answer.
            $id = basename((string) parse_url($url, PHP_URL_PATH));

            if ($token === 'user-token') {
                return array_key_exists($id, $grant)
                    ? Http::response(['id' => $id, 'name' => 'Page '.$id, 'access_token' => $grant[$id]['token']])
                    : Http::response(['error' => ['message' => 'Unsupported get request.', 'type' => 'GraphMethodException', 'code' => 100]], 400);
            }

            return Http::response(['id' => $id]);
        });
    }
}
