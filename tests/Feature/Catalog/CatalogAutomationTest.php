<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\ApplyAutoPublishRules;
use App\Actions\ApproveDraft;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Jobs\RenderCatalogVideoJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MakesCatalogItems;
use Tests\TestCase;

/**
 * Phase 2: a leaflet that comes into the hub becomes a draft by itself, and waits for a person until the owner turns
 * that off. The switch is per rule, on by default, and holds the whole draft.
 */
final class CatalogAutomationTest extends TestCase
{
    use MakesCatalogItems;
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        // Three in the afternoon, in the posting window the brand has from 18:30: the slot is this evening.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03 15:00', 'Europe/Zagreb'));
        Queue::fake();
        Http::fake(['*' => Http::response('ok')]);
        config()->set('elevenlabs.api_key', null);

        $this->brand = Brand::factory()->create([
            'slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com',
            'voice' => ['cta' => 'Preuzmi Listo'], 'posting_windows' => [['from' => '18:30', 'to' => '21:30']],
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_new_rule_drafts_and_renders_by_itself_but_waits_for_approval(): void
    {
        $item = $this->catalogItem($this->brand);
        $source = $item->source;
        $this->catalogAccounts($this->brand);
        $source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true]);
        $source->autoPublishRules()->create(['platform' => Platform::FacebookPage, 'enabled' => true]);

        $this->assertSame(1, app(ApplyAutoPublishRules::class)->execute($source));

        $draft = PostDraft::query()->firstOrFail();
        $this->assertSame(DraftStatus::PendingApproval, $draft->status);
        $this->assertSame('18:30', $draft->scheduled_at->setTimezone('Europe/Zagreb')->format('H:i'), 'the usual evening slot, kept for when it is approved');
        $this->assertCount(2, $draft->variants);
        Queue::assertPushed(RenderCatalogVideoJob::class, 1);
    }

    public function test_with_the_switch_off_the_same_rule_schedules_it_for_posting(): void
    {
        $item = $this->catalogItem($this->brand);
        $this->catalogAccounts($this->brand);
        $item->source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true, 'requires_approval' => false]);

        app(ApplyAutoPublishRules::class)->execute($item->source);

        $this->assertSame(DraftStatus::Scheduled, PostDraft::query()->firstOrFail()->status);
    }

    public function test_one_rule_that_asks_for_a_person_holds_the_draft_for_every_channel(): void
    {
        $item = $this->catalogItem($this->brand);
        $this->catalogAccounts($this->brand);
        $item->source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true, 'requires_approval' => false]);
        $item->source->autoPublishRules()->create(['platform' => Platform::InstagramBusiness, 'enabled' => true, 'requires_approval' => true]);

        app(ApplyAutoPublishRules::class)->execute($item->source);

        $this->assertSame(DraftStatus::PendingApproval, PostDraft::query()->firstOrFail()->status);
    }

    public function test_a_rule_that_is_off_makes_no_draft_whatever_the_switch_says(): void
    {
        $item = $this->catalogItem($this->brand);
        $this->catalogAccounts($this->brand);
        $item->source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => false]);

        $this->assertSame(0, app(ApplyAutoPublishRules::class)->execute($item->source));
        $this->assertSame(0, PostDraft::query()->count());
    }

    public function test_approving_keeps_the_slot_the_draft_was_given(): void
    {
        $item = $this->catalogItem($this->brand);
        $this->catalogAccounts($this->brand);
        $item->source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true]);
        app(ApplyAutoPublishRules::class)->execute($item->source);
        $draft = PostDraft::query()->firstOrFail();
        $slot = $draft->scheduled_at;

        $approved = app(ApproveDraft::class)->execute($draft, approverId: null);

        $this->assertSame(DraftStatus::Scheduled, $approved->status);
        $this->assertTrue($approved->scheduled_at->equalTo($slot));
    }

    public function test_a_leaflet_older_than_three_days_is_not_news_and_is_left_alone(): void
    {
        $item = $this->catalogItem($this->brand, itemAttributes: ['published_at' => now()->subDays(5)]);
        $this->catalogAccounts($this->brand);
        $item->source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true]);

        $this->assertSame(0, app(ApplyAutoPublishRules::class)->execute($item->source), 'a first sync of a source that holds a month of leaflets queues nothing for them');
    }

    public function test_a_leaflet_that_ends_before_a_viewer_can_use_it_is_not_posted(): void
    {
        $item = $this->catalogItem($this->brand, itemAttributes: ['expires_at' => now()->addDays(2)]);
        $this->catalogAccounts($this->brand);
        $item->source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true]);

        $this->assertSame(0, app(ApplyAutoPublishRules::class)->execute($item->source));
    }

    public function test_the_same_leaflet_is_never_drafted_twice(): void
    {
        $item = $this->catalogItem($this->brand);
        $this->catalogAccounts($this->brand);
        $item->source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true]);

        $this->assertSame(1, app(ApplyAutoPublishRules::class)->execute($item->source));
        $this->assertSame(0, app(ApplyAutoPublishRules::class)->execute($item->source->refresh()));
        $this->assertSame(1, ContentItem::query()->has('postDrafts')->count());
    }

    public function test_a_draft_waiting_for_approval_holds_its_slot_so_the_next_one_goes_after_it(): void
    {
        $first = $this->catalogItem($this->brand);
        $this->catalogAccounts($this->brand);
        $first->source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true]);
        app(ApplyAutoPublishRules::class)->execute($first->source);

        $second = $this->catalogItem($this->brand, $this->catalogFeedItem('lidl-2026-10-05'), ['external_id' => 'catalog:second']);
        $second->source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true]);
        app(ApplyAutoPublishRules::class)->execute($second->source);

        $slots = PostDraft::query()->orderBy('scheduled_at')->get()->map(fn (PostDraft $draft): string => $draft->scheduled_at->setTimezone('Europe/Zagreb')->format('H:i'))->all();

        $this->assertSame(['18:30', '19:15'], $slots);
    }
}
