<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Sources\ContentItemData;

/**
 * A new-catalog item as the real feed sends it — the fixtures are what Listo's `/v1/social-feed?kind=catalog`
 * answered for the leaflets of 3 October 2026 (tests/Fixtures/catalog-feed-*.json) — in a hub with a brand and its
 * channels.
 */
trait MakesCatalogItems
{
    /**
     * @return array<string, mixed> The `items[0]` of a fixture feed.
     */
    protected function catalogFeedItem(string $name = 'konzum-2026-10-07'): array
    {
        $payload = json_decode((string) file_get_contents(base_path("tests/Fixtures/catalog-feed-{$name}.json")), true, flags: JSON_THROW_ON_ERROR);

        return $payload['items'][0];
    }

    /**
     * @param  array<string, mixed>  $feedItem
     * @param  array<string, mixed>  $itemAttributes
     */
    protected function catalogItem(?Brand $brand = null, ?array $feedItem = null, array $itemAttributes = []): ContentItem
    {
        $brand ??= Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com', 'voice' => ['cta' => 'Preuzmi Listo', 'hashtags' => ['listo']]]);
        $source = Source::factory()->for($brand)->create(['name' => 'listo-katalozi']);
        $data = ContentItemData::fromFeedItem($feedItem ?? $this->catalogFeedItem());

        return ContentItem::query()->create([
            ...$data->toAttributes(),
            'source_id' => $source->id,
            'brand_id' => $brand->id,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            // The fixture is a leaflet that came out on 3 October; a test runs on another day.
            'published_at' => now()->subHours(2),
            'expires_at' => now()->addDays(8),
            ...$itemAttributes,
        ]);
    }

    /**
     * @param  list<Platform>  $platforms
     * @return list<SocialAccount>
     */
    protected function catalogAccounts(Brand $brand, array $platforms = [Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok]): array
    {
        return array_map(
            fn (Platform $platform): SocialAccount => SocialAccount::factory()->for($brand)->create(['platform' => $platform, 'name' => $platform->label()]),
            $platforms,
        );
    }
}
