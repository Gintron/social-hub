<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\SourceType;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\PostDrafts\PostDraftResource;
use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Filament\Resources\Sources\SourceResource;
use App\Filament\Support\HubStatus;
use App\Filament\Widgets\AttentionOverview;
use App\Filament\Widgets\BrandsOverview;
use App\Filament\Widgets\PendingApprovalTable;
use App\Filament\Widgets\SetupGuide;
use App\Filament\Widgets\UpcomingPostsTable;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The first screen answers two questions: what do I do next, and is anything broken. In an empty hub
 * the answer to the first is a short guide; once it is set up, the guide is gone and the numbers stay.
 */
final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    public function test_an_empty_hub_is_shown_the_four_steps_to_a_first_post(): void
    {
        // Widgets load lazily on the page, so the guide is asked for directly.
        $this->get('/admin')->assertOk();

        Livewire::test(SetupGuide::class)
            ->assertSee('Kako početi')
            ->assertSee('0 od 4 koraka gotovo')
            ->assertSee('Dodaj brend')
            ->assertSee('Poveži izvor sadržaja')
            ->assertSee('Poveži račune društvenih mreža')
            ->assertSee('Napravi prvu objavu');

        $this->assertFalse(app(HubStatus::class)->setupComplete());
        $this->assertTrue(SetupGuide::canView());
    }

    public function test_every_step_knows_where_in_the_panel_it_is_done(): void
    {
        $urls = array_column(app(HubStatus::class)->setupSteps(), 'url');

        $this->assertSame([
            BrandResource::getUrl('create'),
            SourceResource::getUrl('create'),
            SocialAccountResource::getUrl('index'),
            ContentItemResource::getUrl('index'),
        ], $urls);
    }

    public function test_the_guide_ticks_steps_as_they_are_done_and_goes_when_all_are_(): void
    {
        $brand = Brand::factory()->create();
        $this->assertSame([true, false, false, false], array_column(app(HubStatus::class)->setupSteps(), 'done'));

        $source = Source::factory()->for($brand)->create();
        SocialAccount::factory()->for($brand)->create();
        $this->assertSame([true, true, true, false], array_column(app(HubStatus::class)->setupSteps(), 'done'));
        $this->assertTrue(SetupGuide::canView());

        PostDraft::factory()->for($brand)->create();

        $this->assertTrue(app(HubStatus::class)->setupComplete());
        $this->assertFalse(SetupGuide::canView());
        $this->get('/admin')->assertOk();
        $this->assertNotNull($source);
    }

    public function test_an_account_that_is_not_active_does_not_count_as_connected(): void
    {
        SocialAccount::factory()->create(['status' => AccountStatus::NeedsReconnect]);

        $this->assertFalse(app(HubStatus::class)->setupSteps()[2]['done']);
    }

    public function test_the_numbers_count_what_asks_for_a_person(): void
    {
        $brand = Brand::factory()->create();

        PostDraft::factory()->for($brand)->count(2)->create(['status' => DraftStatus::PendingApproval]);
        PostDraft::factory()->for($brand)->create(['status' => DraftStatus::Scheduled, 'scheduled_at' => now()->addDays(2)]);
        PostDraft::factory()->for($brand)->create(['status' => DraftStatus::Approved, 'scheduled_at' => now()->addDays(3)]);
        // Further than a week away, or already past: neither is "this week".
        PostDraft::factory()->for($brand)->create(['status' => DraftStatus::Scheduled, 'scheduled_at' => now()->addDays(20)]);
        PostDraft::factory()->for($brand)->create(['status' => DraftStatus::Scheduled, 'scheduled_at' => now()->subDay()]);
        PostDraft::factory()->for($brand)->create(['status' => DraftStatus::Failed]);
        PostDraft::factory()->for($brand)->create(['status' => DraftStatus::PartiallyPublished]);
        PostDraft::factory()->for($brand)->create(['status' => DraftStatus::Published]);

        $status = app(HubStatus::class);

        $this->assertSame(2, $status->pendingApproval());
        $this->assertSame(2, $status->scheduledThisWeek());
        $this->assertSame(2, $status->needFixing());
    }

    public function test_an_account_is_flagged_when_it_needs_a_reconnect_or_its_token_is_about_to_end(): void
    {
        $brand = Brand::factory()->create();

        SocialAccount::factory()->for($brand)->create(['status' => AccountStatus::NeedsReconnect]);
        SocialAccount::factory()->for($brand)->create(['token_expires_at' => now()->addDays(3)]);
        SocialAccount::factory()->for($brand)->create(['token_expires_at' => now()->addDays(60)]);
        SocialAccount::factory()->for($brand)->create(['token_expires_at' => null]);
        // Switched off on purpose: nobody is waiting for it.
        SocialAccount::factory()->for($brand)->create(['status' => AccountStatus::Disabled, 'token_expires_at' => now()->subDay()]);

        $this->assertSame(2, app(HubStatus::class)->accountsToReconnect());
    }

    public function test_a_source_is_flagged_when_it_failed_or_has_gone_quiet(): void
    {
        $brand = Brand::factory()->create();

        Source::factory()->for($brand)->create(['last_synced_at' => now()->subHour(), 'last_error' => null]);
        Source::factory()->for($brand)->create(['last_synced_at' => now()->subHour(), 'last_error' => 'HTTP 500']);
        Source::factory()->for($brand)->create(['last_synced_at' => now()->subHours(HubStatus::SOURCE_STALE_AFTER_HOURS + 1)]);
        // Off on purpose, so its silence is not a problem.
        Source::factory()->for($brand)->create(['enabled' => false, 'last_error' => 'HTTP 500']);
        // A webhook source is pushed to, not read: nothing arriving is not a failed sync.
        Source::factory()->for($brand)->create(['type' => SourceType::Webhook, 'last_synced_at' => null]);

        $this->assertSame(2, app(HubStatus::class)->sourcesWithProblems());
    }

    public function test_each_number_links_to_the_list_that_explains_it(): void
    {
        $brand = Brand::factory()->create();
        PostDraft::factory()->for($brand)->create(['status' => DraftStatus::PendingApproval]);

        Livewire::test(AttentionOverview::class)
            ->assertOk()
            ->assertSee('Čeka tvoje odobrenje')
            ->assertSee(PostDraftResource::getUrl('index', ['tab' => 'pending']), escape: false)
            ->assertSee(PostDraftResource::getUrl('index', ['tab' => 'failed']), escape: false);
    }

    public function test_the_approval_list_holds_only_what_waits_for_approval_oldest_first(): void
    {
        $brand = Brand::factory()->create();
        $newer = PostDraft::factory()->for($brand)->create(['title' => 'Novija objava', 'created_at' => now()->subHour()]);
        $older = PostDraft::factory()->for($brand)->create(['title' => 'Starija objava', 'created_at' => now()->subDay()]);
        $done = PostDraft::factory()->for($brand)->create(['title' => 'Objavljena objava', 'status' => DraftStatus::Published]);

        Livewire::test(PendingApprovalTable::class)
            ->assertCanSeeTableRecords([$older, $newer], inOrder: true)
            ->assertCanNotSeeTableRecords([$done]);
    }

    public function test_the_upcoming_list_holds_approved_posts_with_a_time_ahead_soonest_first(): void
    {
        $brand = Brand::factory()->create();
        $later = PostDraft::factory()->for($brand)->create(['title' => 'Kasnije', 'status' => DraftStatus::Scheduled, 'scheduled_at' => now()->addDays(3)]);
        $soon = PostDraft::factory()->for($brand)->create(['title' => 'Uskoro', 'status' => DraftStatus::Approved, 'scheduled_at' => now()->addHours(5)]);
        $past = PostDraft::factory()->for($brand)->create(['title' => 'Prošlo', 'status' => DraftStatus::Scheduled, 'scheduled_at' => now()->subHour()]);
        $waiting = PostDraft::factory()->for($brand)->create(['title' => 'Čeka', 'status' => DraftStatus::PendingApproval, 'scheduled_at' => now()->addHour()]);

        Livewire::test(UpcomingPostsTable::class)
            ->assertCanSeeTableRecords([$soon, $later], inOrder: true)
            ->assertCanNotSeeTableRecords([$past, $waiting]);
    }

    public function test_the_brand_overview_is_for_more_than_one_brand(): void
    {
        Brand::factory()->create();
        $this->assertFalse(BrandsOverview::canView());

        Brand::factory()->create();
        $this->assertTrue(BrandsOverview::canView());
    }

    public function test_each_brand_row_says_what_is_connected_waiting_and_broken(): void
    {
        $quiet = Brand::factory()->create(['name' => 'Tihi brend']);
        $busy = Brand::factory()->create(['name' => 'Zauzeti brend']);

        // The quiet one is set up halfway: a source and no account.
        Source::factory()->for($quiet)->create();

        Source::factory()->for($busy)->create(['last_error' => null, 'last_synced_at' => now()->subHour()]);
        Source::factory()->for($busy)->create(['last_error' => 'HTTP 500', 'last_synced_at' => now()->subHour()]);
        SocialAccount::factory()->for($busy)->create();
        SocialAccount::factory()->for($busy)->create(['status' => AccountStatus::NeedsReconnect]);
        PostDraft::factory()->for($busy)->count(3)->create(['status' => DraftStatus::PendingApproval]);
        PostDraft::factory()->for($busy)->create(['status' => DraftStatus::Scheduled, 'scheduled_at' => now()->addDay()]);

        Livewire::test(BrandsOverview::class)
            ->assertCanSeeTableRecords([$quiet, $busy])
            ->assertSee('1 od 2 s greškom')
            ->assertSee('1 od 2 traži pažnju')
            // The quiet brand has a source but no account: said as "nema", not as a zero.
            ->assertSee('nema')
            ->assertSee('još nijedna');
    }

    public function test_the_navigation_follows_the_work_not_the_database(): void
    {
        $this->assertSame('Objavljivanje', PostDraftResource::getNavigationGroup());
        $this->assertSame('Sadržaj', ContentItemResource::getNavigationGroup());
        $this->assertSame('Sadržaj', SourceResource::getNavigationGroup());
        $this->assertSame('Postavke', BrandResource::getNavigationGroup());
        $this->assertSame('Postavke', SocialAccountResource::getNavigationGroup());
        $this->assertSame(['Objavljivanje', 'Sadržaj', 'Postavke'], array_values(filament()->getDefaultPanel()->getNavigationGroups()));
    }

    public function test_a_channel_has_a_short_name_for_places_where_space_is_short(): void
    {
        foreach (Platform::cases() as $platform) {
            $this->assertNotSame('', $platform->shortLabel());
            $this->assertLessThanOrEqual(mb_strlen($platform->label()), mb_strlen($platform->shortLabel()));
        }

        $this->assertSame('FB grupa', Platform::FacebookGroup->shortLabel());
    }

    public function test_the_content_list_names_where_a_post_starts(): void
    {
        $item = ContentItem::factory()->create(['title' => 'Konobar/ica']);

        $this->get(ContentItemResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Sadržaj iz izvora')
            ->assertSee($item->title);
    }
}
