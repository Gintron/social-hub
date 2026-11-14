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
 * A dish as the real feed sends it. The fixtures are what Listo's `/v1/social-feed?kind=recipe` says for the dishes its public
 * API listed on 14 November 2026 (built by Listo's own `recipeItem`, tests/Fixtures/recipe-feed-*.json): the Croatian market
 * for `uselisto`, the Austrian for `uselisto-at`.
 */
trait MakesRecipeItems
{
    /**
     * @return array<string, mixed> One item of a fixture feed.
     */
    protected function recipeFeedItem(int $index = 0, string $market = 'hr'): array
    {
        $payload = json_decode((string) file_get_contents(base_path("tests/Fixtures/recipe-feed-{$market}-2026-11-14.json")), true, flags: JSON_THROW_ON_ERROR);

        return $payload['items'][$index];
    }

    protected function recipeBrand(string $market = 'hr', array $attributes = []): Brand
    {
        return $market === 'at'
            ? Brand::factory()->create([
                'slug' => 'uselisto-at', 'name' => 'Listo', 'site_url' => 'https://uselisto.com/at', 'language' => 'de', 'timezone' => 'Europe/Vienna',
                'voice' => ['cta' => 'Hol dir Listo', 'hashtags' => ['listo', 'angebote']],
                ...$attributes,
            ])
            : Brand::factory()->create([
                'slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com', 'language' => 'hr',
                'voice' => ['cta' => 'Preuzmi Listo', 'hashtags' => ['listo', 'akcije']],
                ...$attributes,
            ]);
    }

    /**
     * @param  array<string, mixed>|null  $feedItem
     * @param  array<string, mixed>  $itemAttributes
     */
    protected function recipeItem(?Brand $brand = null, ?array $feedItem = null, array $itemAttributes = [], string $market = 'hr'): ContentItem
    {
        $brand ??= $this->recipeBrand($market);
        $source = Source::query()->where('brand_id', $brand->id)->where('name', 'listo-recepti')->first()
            ?? Source::factory()->for($brand)->create(['name' => 'listo-recepti']);
        $data = ContentItemData::fromFeedItem($feedItem ?? $this->recipeFeedItem(0, $market));

        return ContentItem::query()->create([
            ...$data->toAttributes(),
            'source_id' => $source->id,
            'brand_id' => $brand->id,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            // The fixture dates from 12 November; a test runs on another day.
            'published_at' => now()->subHours(2),
            'expires_at' => now()->addDays(5),
            ...$itemAttributes,
        ]);
    }

    /**
     * @param  list<Platform>  $platforms
     * @return list<SocialAccount>
     */
    protected function recipeAccounts(Brand $brand, array $platforms = [Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok]): array
    {
        return array_map(
            fn (Platform $platform): SocialAccount => SocialAccount::factory()->for($brand)->create(['platform' => $platform, 'name' => $platform->label()]),
            $platforms,
        );
    }
}
