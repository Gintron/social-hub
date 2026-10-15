<?php

declare(strict_types=1);

namespace Tests\Feature\Drafting;

use App\Actions\ApplyAutoPublishRules;
use App\Actions\AutoLineup;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Jobs\RenderCatalogVideoJob;
use App\Jobs\RenderMediaJob;
use App\Jobs\RenderSlidesJob;
use App\Jobs\RenderVideoJob;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Sources\ContentItemData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MakesCatalogItems;
use Tests\TestCase;

/**
 * Brands on the lineup: one automatic post per posting window, chosen by the brand's own order of kinds.
 */
final class AutoLineupTest extends TestCase
{
    use MakesCatalogItems;
    use RefreshDatabase;

    /**
     * Item pages that answer 404.
     *
     * @var list<string>
     */
    private array $gone = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, a quarter to twelve: the 12:00 window opens within the 30 minutes the lineup fills it in.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 11:45', 'Europe/Zagreb'));

        Queue::fake([RenderMediaJob::class, RenderSlidesJob::class, RenderVideoJob::class, RenderCatalogVideoJob::class]);
        Http::fake(fn (Request $request) => in_array($request->url(), $this->gone, true)
            ? Http::response('', 404)
            : Http::response('', 200));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_catalog_takes_the_window_before_a_comparison_and_a_deal(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Deal, 'deal:1', priority: 99);
        $this->item($source, ContentKind::Comparison, 'comparison:1', priority: 50);
        $this->item($source, ContentKind::Catalog, 'catalog:1', priority: 1);

        $this->assertSame(1, $this->lineup($brand));

        $draft = PostDraft::query()->with('contentItems')->sole();
        $this->assertSame(ContentKind::Catalog, $draft->contentItems->sole()->kind);
        $this->assertSame('2026-09-07 12:00', $this->zagreb($draft));
        $this->assertSame(DraftStatus::Scheduled, $draft->status, 'an approved draft with a slot is scheduled, as in ApplyAutoPublishRules');
    }

    public function test_without_a_catalog_a_comparison_comes_before_a_deal(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Deal, 'deal:1', priority: 99);
        $this->item($source, ContentKind::Comparison, 'comparison:1', priority: 1);

        $this->lineup($brand);

        $this->assertSame(ContentKind::Comparison, PostDraft::query()->with('contentItems')->sole()->contentItems->sole()->kind);
    }

    public function test_a_deal_fills_the_window_when_nothing_else_waits(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Deal, 'deal:1', priority: 1);

        $this->lineup($brand);

        $this->assertSame(ContentKind::Deal, PostDraft::query()->with('contentItems')->sole()->contentItems->sole()->kind);
    }

    public function test_each_window_gets_one_post_and_the_next_window_takes_the_next_catalog(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Catalog, 'catalog:1', priority: 2);
        $this->item($source, ContentKind::Catalog, 'catalog:2', priority: 1);

        $this->assertSame(1, $this->lineup($brand));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:10', 'Europe/Zagreb'));
        $this->assertSame(0, $this->lineup($brand), 'the window is taken, so a second run adds nothing');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 17:45', 'Europe/Zagreb'));
        $this->assertSame(1, $this->lineup($brand));

        $drafts = PostDraft::query()->with('contentItems')->orderBy('scheduled_at')->get();
        $this->assertSame(['2026-09-07 12:00', '2026-09-07 18:00'], $drafts->map(fn (PostDraft $draft): string => $this->zagreb($draft))->all());
        $this->assertSame(['catalog:1', 'catalog:2'], $drafts->map(fn (PostDraft $draft): string => $draft->contentItems->sole()->external_id)->all(), 'the higher priority catalog takes the first window');
    }

    public function test_nothing_is_scheduled_outside_an_open_window_or_its_lead(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Catalog, 'catalog:1');

        // Between windows, and just before the 18:00 window's lead starts at 17:30.
        foreach (['2026-09-07 09:10', '2026-09-07 10:00', '2026-09-07 17:20'] as $moment) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($moment, 'Europe/Zagreb'));
            $this->assertSame(0, $this->lineup($brand), "nothing at {$moment}");
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 17:30', 'Europe/Zagreb'));
        $this->assertSame(1, $this->lineup($brand), 'the window fills 30 minutes before it opens');
        $this->assertSame('2026-09-07 18:00', $this->zagreb(PostDraft::query()->sole()));
    }

    public function test_a_catalog_that_arrives_after_the_window_was_taken_waits_for_the_next_window(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Deal, 'deal:1', priority: 1);

        $this->lineup($brand);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:20', 'Europe/Zagreb'));
        $this->item($source, ContentKind::Catalog, 'catalog:late', priority: 100);
        $this->assertSame(0, $this->lineup($brand), 'the planned deal keeps its window');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 17:45', 'Europe/Zagreb'));
        $this->assertSame(1, $this->lineup($brand));
        $this->assertSame(ContentKind::Catalog, PostDraft::query()->with('contentItems')->latest('id')->firstOrFail()->contentItems->sole()->kind);
    }

    public function test_the_daily_limit_stops_the_lineup_for_the_day(): void
    {
        $brand = $this->brand(['daily_post_limit' => 1]);
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Catalog, 'catalog:1', priority: 2);
        $this->item($source, ContentKind::Catalog, 'catalog:2', priority: 1);

        $this->assertSame(1, $this->lineup($brand));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 17:45', 'Europe/Zagreb'));
        $this->assertSame(0, $this->lineup($brand), 'the day already has its one post');
    }

    public function test_a_daily_limit_above_the_window_count_is_shared_out_over_the_windows(): void
    {
        // 5 posts over 3 windows: the first two windows take two each, the last one takes one.
        $brand = $this->brand(['daily_post_limit' => 5]);
        $source = $this->sourceWithRule($brand);
        foreach (range(1, 6) as $i) {
            $this->item($source, ContentKind::Deal, "deal:{$i}", priority: 10 - $i);
        }

        $runs = [
            '2026-09-07 07:45' => 1,
            '2026-09-07 08:30' => 1,
            '2026-09-07 08:40' => 0,
            '2026-09-07 11:45' => 1,
            '2026-09-07 12:50' => 1,
            '2026-09-07 13:10' => 0,
            '2026-09-07 17:45' => 1,
        ];

        foreach ($runs as $moment => $expected) {
            $this->assertSame($expected, $this->lineup($brand, $moment), "at {$moment}");
        }

        $this->assertSame(
            ['2026-09-07 07:45', '2026-09-07 08:30', '2026-09-07 12:00', '2026-09-07 12:50', '2026-09-07 18:00'],
            PostDraft::query()->orderBy('scheduled_at')->get()->map(fn (PostDraft $draft): string => $this->zagreb($draft))->all(),
        );
    }

    public function test_the_brands_own_order_decides_and_a_kind_left_out_is_never_posted(): void
    {
        $brand = $this->brand(['lineup_priority' => ['deal', 'catalog']]);
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Catalog, 'catalog:1', priority: 100);
        $this->item($source, ContentKind::Deal, 'deal:1', priority: 1);

        $this->lineup($brand);

        $this->assertSame(ContentKind::Deal, PostDraft::query()->with('contentItems')->sole()->contentItems->sole()->kind, 'the deal is first in this brand\'s order');

        // A comparison is not in this brand's order, so it is never posted, even when it is the only item left.
        $this->item($source, ContentKind::Comparison, 'comparison:1', priority: 100);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 17:45', 'Europe/Zagreb'));
        $this->assertSame(1, $this->lineup($brand), 'the catalog is still in the order, so it fills the evening window');
        $this->assertSame(
            ContentKind::Catalog,
            PostDraft::query()->with('contentItems')->latest('id')->firstOrFail()->contentItems->sole()->kind,
        );
    }

    public function test_an_empty_order_posts_nothing(): void
    {
        $brand = $this->brand(['lineup_priority' => []]);
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Catalog, 'catalog:1');

        $this->assertSame(0, $this->lineup($brand));
        $this->assertSame(0, PostDraft::query()->count());
    }

    public function test_a_channel_at_its_daily_cap_is_left_out(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand, dailyCap: 1);
        $this->item($source, ContentKind::Catalog, 'catalog:1', priority: 2);
        $this->item($source, ContentKind::Catalog, 'catalog:2', priority: 1);

        $this->assertSame(1, $this->lineup($brand));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 17:45', 'Europe/Zagreb'));
        $this->assertSame(0, $this->lineup($brand), 'the only channel has used its one post today');
    }

    public function test_a_rule_that_asks_for_approval_holds_the_post_but_still_takes_the_window(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand, requiresApproval: true);
        $this->item($source, ContentKind::Catalog, 'catalog:1', priority: 2);
        $this->item($source, ContentKind::Deal, 'deal:1', priority: 1);

        $this->lineup($brand);

        $this->assertSame(DraftStatus::PendingApproval, PostDraft::query()->sole()->status);
        $this->assertSame(0, $this->lineup($brand, '2026-09-07 12:10'), 'a waiting post still holds its window');
    }

    public function test_an_item_whose_page_is_gone_is_passed_over(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->gone = ['https://example.test/katalog/dead'];
        $this->item($source, ContentKind::Catalog, 'catalog:dead', priority: 9, url: 'https://example.test/katalog/dead');
        $this->item($source, ContentKind::Catalog, 'catalog:alive', priority: 1);

        $this->lineup($brand);

        $this->assertSame('catalog:alive', PostDraft::query()->with('contentItems')->sole()->contentItems->sole()->external_id);
    }

    public function test_a_catalog_without_a_usable_demo_is_passed_over_and_leaves_no_draft_behind(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $bare = $this->item($source, ContentKind::Catalog, 'catalog:bare', priority: 9);
        $bare->forceFill(['raw' => []])->save();
        $this->item($source, ContentKind::Deal, 'deal:1', priority: 1);

        $this->assertSame(1, $this->lineup($brand));
        $this->assertSame(
            ['deal:1'],
            PostDraft::query()->with('contentItems')->get()->flatMap->contentItems->pluck('external_id')->all(),
            'a draft saved before its video failed would hold the window with no media',
        );
    }

    public function test_an_item_no_channel_takes_is_passed_over_and_the_window_goes_to_the_next(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        // One the source marked, one the words give away: both stay off every channel, and neither holds the window.
        $this->item($source, ContentKind::Deal, 'deal:marked', priority: 99, extra: ['title' => 'Akcija tjedna', 'restricted' => ['alcohol']]);
        $this->item($source, ContentKind::Deal, 'deal:wine', priority: 90, extra: ['title' => 'Vino Graševina 0,75 l']);
        $this->item($source, ContentKind::Deal, 'deal:coffee', priority: 1, extra: ['title' => 'Kava 500 g']);

        $this->assertSame(1, $this->lineup($brand));

        $this->assertSame(
            ['deal:coffee'],
            PostDraft::query()->with('contentItems')->get()->flatMap->contentItems->pluck('external_id')->all(),
            'a draft the publish job would skip as alcohol would only have held the window',
        );
    }

    public function test_alcohol_that_is_all_there_is_leaves_the_window_empty(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Deal, 'deal:wine', priority: 90, extra: ['title' => 'Pivo Ožujsko 0,5 l']);

        $this->assertSame(0, $this->lineup($brand));
        $this->assertSame(0, PostDraft::query()->count());
    }

    public function test_a_kind_the_brand_ranks_first_is_not_crowded_out_by_a_hundred_of_another(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);

        // More deals, and all ranked higher, than the candidates the lineup looks at: the leaflet still comes first.
        foreach (range(1, 101) as $number) {
            $this->item($source, ContentKind::Deal, "deal:{$number}", priority: 500 + $number);
        }

        $this->item($source, ContentKind::Catalog, 'catalog:1', priority: 1);

        $this->assertSame(1, $this->lineup($brand));
        $this->assertSame(ContentKind::Catalog, PostDraft::query()->with('contentItems')->sole()->contentItems->sole()->kind);
    }

    public function test_the_window_opens_at_its_local_time_on_the_day_the_clocks_go_back(): void
    {
        // 25 October 2026 has 25 hours; the 12:00 window opens at 12:00 on the wall, not at 11:00.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 11:15', 'Europe/Zagreb'));
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Deal, 'deal:1');

        $this->assertSame(0, $this->lineup($brand), 'at 11:15 the 12:00 window is still more than its lead away');

        $this->assertSame(1, $this->lineup($brand, '2026-10-25 11:45'));
        $this->assertSame('2026-10-25 12:00', $this->zagreb(PostDraft::query()->sole()));
    }

    public function test_the_window_opens_at_its_local_time_on_the_day_the_clocks_go_forward(): void
    {
        // 29 March 2026 has 23 hours; at 12:15 the 12:00 window is open, not an hour away.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-29 12:15', 'Europe/Zagreb'));
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Deal, 'deal:1');

        $this->assertSame(1, $this->lineup($brand));
        $this->assertSame('2026-03-29 12:15', $this->zagreb(PostDraft::query()->sole()));
    }

    public function test_the_auto_publish_entry_point_fills_the_window_for_a_brand_on_the_lineup(): void
    {
        $brand = $this->brand();
        $source = $this->sourceWithRule($brand);
        $this->item($source, ContentKind::Catalog, 'catalog:1', priority: 1);

        $this->assertSame(1, app(ApplyAutoPublishRules::class)->execute($source->refresh()));
        $this->assertSame('2026-09-07 12:00', $this->zagreb(PostDraft::query()->sole()));
    }

    public function test_the_hourly_pass_fills_only_brands_on_the_lineup(): void
    {
        $lineup = $this->brand();
        $this->item($this->sourceWithRule($lineup), ContentKind::Catalog, 'catalog:1');

        $other = $this->brand(['slug' => 'radim-hr', 'auto_lineup' => false]);
        $this->item($this->sourceWithRule($other), ContentKind::Catalog, 'catalog:other');

        $this->artisan('hub:auto-lineup')->assertSuccessful();

        $this->assertSame(1, PostDraft::query()->count());
        $this->assertSame($lineup->id, PostDraft::query()->sole()->brand_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function brand(array $overrides = []): Brand
    {
        return Brand::factory()->create($overrides + [
            'slug' => 'uselisto',
            'timezone' => 'Europe/Zagreb',
            'posting_windows' => [
                ['from' => '07:30', 'to' => '09:00'],
                ['from' => '12:00', 'to' => '13:30'],
                ['from' => '18:00', 'to' => '21:00'],
            ],
            'daily_post_limit' => 3,
            'auto_lineup' => true,
        ]);
    }

    private function sourceWithRule(Brand $brand, ?int $dailyCap = null, bool $requiresApproval = false): Source
    {
        $source = Source::factory()->for($brand)->create(['base_url' => 'https://site.test/api/social-feed', 'secret' => 'token']);

        SocialAccount::factory()->for($brand)->create();

        AutoPublishRule::query()->create([
            'source_id' => $source->id,
            'platform' => Platform::FacebookPage,
            'enabled' => true,
            'requires_approval' => $requiresApproval,
            'delay_minutes' => 0,
            'daily_cap' => $dailyCap,
            'format' => null,
            'settings' => null,
        ]);

        return $source;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function item(Source $source, ContentKind $kind, string $externalId, int $priority = 3, ?string $url = null, array $extra = []): ContentItem
    {
        $attributes = [
            'kind' => $kind,
            'external_id' => $externalId,
            'title' => $externalId,
            'url' => $url ?? 'https://example.test/'.str_replace(':', '/', $externalId),
            'priority' => $priority,
            'published_at' => CarbonImmutable::now()->subHour(),
            'expires_at' => CarbonImmutable::now()->addDays(10),
            ...$extra,
        ];

        // A leaflet is made from the feed as Listo sends it, with the demo block its video needs.
        if ($kind === ContentKind::Catalog) {
            return ContentItem::query()->create([
                ...ContentItemData::fromFeedItem($this->catalogFeedItem())->toAttributes(),
                ...$attributes,
                'source_id' => $source->id,
                'brand_id' => $source->brand_id,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        return ContentItem::factory()->for($source)->create($attributes);
    }

    /**
     * Runs the lineup for the brand at the current test time, or at `$at` (a Zagreb time) if given.
     */
    private function lineup(Brand $brand, ?string $at = null): int
    {
        if ($at !== null) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($at, 'Europe/Zagreb'));
        }

        return app(AutoLineup::class)->fill($brand);
    }

    private function zagreb(PostDraft $draft): string
    {
        return $draft->scheduled_at->setTimezone('Europe/Zagreb')->format('Y-m-d H:i');
    }
}
