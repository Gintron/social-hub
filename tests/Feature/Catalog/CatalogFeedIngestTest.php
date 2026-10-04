<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\CatalogDemo;
use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use App\Sources\FeedValidator;
use App\Sources\SourceSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MakesCatalogItems;
use Tests\TestCase;

/**
 * A leaflet comes into the hub through the one adapter every brand uses — `kind: catalog` is a value of the contract,
 * not a branch in code — and what comes in is what the video reads.
 */
final class CatalogFeedIngestTest extends TestCase
{
    use MakesCatalogItems;
    use RefreshDatabase;

    public function test_the_feed_listo_answered_for_every_chain_is_valid_social_feed_v1(): void
    {
        foreach (['konzum-2026-10-07', 'konzum-2026-09-30', 'lidl-2026-10-05', 'spar-2026-09-30'] as $name) {
            $payload = json_decode((string) file_get_contents(base_path("tests/Fixtures/catalog-feed-{$name}.json")), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame([], app(FeedValidator::class)->errors($payload), $name);
            $this->assertSame([], CatalogDemo::problems($payload['items'][0]['raw']['demo']), $name);
        }
    }

    public function test_a_sync_stores_the_leaflet_as_a_catalog_item_once_and_only_once(): void
    {
        $brand = Brand::factory()->create(['slug' => 'uselisto']);
        $source = Source::factory()->for($brand)->create(['name' => 'listo-katalozi', 'config' => ['query.kind' => 'catalog']]);
        $feed = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalog-feed-konzum-2026-10-07.json')), true, flags: JSON_THROW_ON_ERROR);
        Http::fake(['*' => Http::response($feed)]);

        $first = app(SourceSyncer::class)->sync($source);
        $second = app(SourceSyncer::class)->sync($source->refresh());

        $this->assertSame(1, $first->created);
        $this->assertSame([0, 0, 1], [$second->created, $second->updated, $second->unchanged], 'the same leaflet is not a new item');

        $item = ContentItem::query()->firstOrFail();
        $this->assertSame(ContentKind::Catalog, $item->kind);
        $this->assertSame('catalog:257404e1-0047-4425-a544-f38f949255e7', $item->external_id);
        $this->assertSame('Konzum', $item->subtitle);
        $this->assertSame('2026-10-13 23:59:59', $item->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(1037, CatalogDemo::fromItem($item)->totalCents);

        // The query of the source reaches the feed: that is how one adapter serves a feed with its own filters.
        Http::assertSent(fn ($request): bool => ($request->data()['kind'] ?? null) === 'catalog');
    }

    public function test_the_item_is_postable_three_days_ahead_only(): void
    {
        $item = $this->catalogItem(itemAttributes: ['expires_at' => now()->addDays(5)]);

        $this->assertTrue($item->isPostableAt(now()->toImmutable()->addDay()), 'a leaflet that is still valid three days after the post');
        $this->assertFalse($item->isPostableAt(now()->toImmutable()->addDays(3)), 'one that ends before a viewer can use it');
    }

    public function test_a_leaflet_stays_news_for_three_days_and_then_automation_leaves_it_alone(): void
    {
        $fresh = $this->catalogItem(itemAttributes: ['published_at' => now()->subHours(5)]);
        $old = $this->catalogItem(Brand::query()->first(), $this->catalogFeedItem('lidl-2026-10-05'), ['published_at' => now()->subDays(5), 'external_id' => 'catalog:old']);
        $deal = ContentItem::factory()->for(Source::query()->first())->for(Brand::query()->first())->create(['kind' => ContentKind::Deal, 'published_at' => now()->subDays(30), 'external_id' => 'deal:1']);

        $ids = ContentItem::query()->stillNews()->pluck('id')->all();

        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($old->id, $ids);
        $this->assertContains($deal->id, $ids, 'a deal never ages out; only a leaflet does');
    }
}
