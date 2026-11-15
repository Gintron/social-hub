<?php

declare(strict_types=1);

namespace Tests\Feature\Recipe;

use App\Actions\AutoLineup;
use App\Drafting\DigestBuilder;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\Support\MakesCatalogItems;
use Tests\Support\MakesRecipeItems;
use Tests\TestCase;

/**
 * A dish in the automation: it has to outlast its post, the lineup takes it by the brand's order, a block that does not add up is
 * passed over, a roundup of dishes has its own headline, and a dish changed after it was drafted does not go out as drafted.
 */
final class RecipeAutomationTest extends TestCase
{
    use MakesCatalogItems;
    use MakesRecipeItems;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, a quarter to twelve: the 12:00 window opens within the 30 minutes the lineup fills it in.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 11:45', 'Europe/Zagreb'));

        Queue::fake();
        Http::fake(fn (Request $request) => Http::response('', 200));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_dish_has_to_outlast_its_post_by_three_days(): void
    {
        $brand = $this->brand();
        $source = $this->source($brand);
        $short = $this->dish($brand, $source, 0, daysLeft: 2);
        $long = $this->dish($brand, $source, 1, daysLeft: 5);
        $slot = CarbonImmutable::now();

        $this->assertFalse($short->isPostableAt($slot), 'ends in two days: a shopper would find it ended');
        $this->assertTrue($long->isPostableAt($slot));
        $this->assertSame([$long->id], ContentItem::query()->where('kind', ContentKind::Recipe->value)->postableAt($slot)->pluck('id')->all());
    }

    public function test_the_lineup_takes_a_dish_by_the_brands_order_and_it_goes_out_with_its_numbers(): void
    {
        $brand = $this->brand();
        $source = $this->source($brand);
        $this->dish($brand, $source, 0);

        $this->assertSame(1, app(AutoLineup::class)->fill($brand));

        $draft = PostDraft::query()->with(['contentItems', 'variants'])->sole();

        $this->assertSame(ContentKind::Recipe, $draft->contentItems->sole()->kind);
        $this->assertStringContainsString('4 od 7 sastojaka je na akciji u trgovini Ribola, zajedno 8,96 €', $draft->variants->sole()->caption);
    }

    public function test_a_dish_whose_numbers_do_not_add_up_is_passed_over_and_the_next_one_is_taken(): void
    {
        $brand = $this->brand();
        $source = $this->source($brand);
        $this->dish($brand, $source, 0, raw: ['ingredients_on_sale' => 9], daysLeft: 6);
        $good = $this->dish($brand, $source, 1);

        $this->assertSame(1, app(AutoLineup::class)->fill($brand));
        $this->assertSame($good->id, PostDraft::query()->with('contentItems')->sole()->contentItems->sole()->id);
    }

    public function test_a_brand_that_has_not_put_recipes_in_its_order_does_not_post_them(): void
    {
        $brand = $this->brand(['lineup_priority' => ['deal']]);
        $this->dish($brand, $this->source($brand), 0);

        $this->assertSame(0, app(AutoLineup::class)->fill($brand));
    }

    public function test_the_default_order_of_a_brand_without_its_own_puts_the_dish_first_and_the_leaflet_last(): void
    {
        $this->assertSame(['recipe', 'comparison', 'deal', 'catalog'], AutoLineup::KINDS);
    }

    public function test_a_waiting_dish_takes_the_window_before_a_leaflet_a_comparison_and_a_deal(): void
    {
        // No `lineup_priority` of its own: the default order decides.
        $brand = $this->brand(['lineup_priority' => null]);
        $source = $this->source($brand);
        $dish = $this->dish($brand, $source, 0);
        $this->otherKind($brand, $source, ContentKind::Catalog, 'catalog:1', priority: 100);
        $this->otherKind($brand, $source, ContentKind::Comparison, 'comparison:1', priority: 100);
        $this->otherKind($brand, $source, ContentKind::Deal, 'deal:1', priority: 100);

        $this->assertSame(1, app(AutoLineup::class)->fill($brand));

        $this->assertSame($dish->id, PostDraft::query()->with('contentItems')->sole()->contentItems->sole()->id);
    }

    public function test_when_no_dish_is_left_the_window_is_not_empty_and_the_leaflet_comes_after_a_comparison_and_a_deal(): void
    {
        $brand = $this->brand(['lineup_priority' => null]);
        $source = $this->source($brand);
        // The only dish ends in two days: it does not outlast its post, so it is not waiting (the end of a leaflet week).
        $this->dish($brand, $source, 0, daysLeft: 2);
        $catalog = $this->otherKind($brand, $source, ContentKind::Catalog, 'catalog:1', priority: 100);
        $deal = $this->otherKind($brand, $source, ContentKind::Deal, 'deal:1', priority: 1);

        $this->assertSame(1, app(AutoLineup::class)->fill($brand));
        $this->assertSame($deal->id, PostDraft::query()->with('contentItems')->sole()->contentItems->sole()->id, 'a deal of priority 1 still goes before a leaflet of priority 100');

        // The next window: nothing else waits, so the leaflet fills it instead of leaving it empty.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 17:45', 'Europe/Zagreb'));
        $this->assertSame(1, app(AutoLineup::class)->fill($brand));
        $this->assertSame($catalog->id, PostDraft::query()->with('contentItems')->orderByDesc('id')->first()->contentItems->sole()->id);
    }

    public function test_each_window_takes_one_dish_until_they_run_out_and_the_order_can_still_be_changed_per_brand(): void
    {
        $brand = $this->brand(['lineup_priority' => null]);
        $source = $this->source($brand);
        $this->dish($brand, $source, 0);
        $this->dish($brand, $source, 1);
        $this->otherKind($brand, $source, ContentKind::Deal, 'deal:1');

        $kinds = [];
        foreach (['2026-09-07 11:45', '2026-09-07 17:45'] as $moment) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($moment, 'Europe/Zagreb'));
            $this->assertSame(1, app(AutoLineup::class)->fill($brand));
            $kinds[] = PostDraft::query()->with('contentItems')->orderByDesc('id')->first()->contentItems->sole()->kind;
        }
        $this->assertSame([ContentKind::Recipe, ContentKind::Recipe], $kinds, 'two windows, two dishes');

        // A brand that wants the leaflet first still says so in its own order, and gets it.
        $other = $this->brand(['slug' => 'uselisto-x', 'lineup_priority' => ['catalog', 'recipe']]);
        $otherSource = $this->source($other);
        $this->dish($other, $otherSource, 2);
        $leaflet = $this->otherKind($other, $otherSource, ContentKind::Catalog, 'catalog:x');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 11:45', 'Europe/Zagreb'));
        $this->assertSame(1, app(AutoLineup::class)->fill($other));
        $this->assertSame($leaflet->id, PostDraft::query()->where('brand_id', $other->id)->with('contentItems')->sole()->contentItems->sole()->id);
    }

    public function test_a_roundup_of_dishes_has_a_headline_in_the_brands_language(): void
    {
        $hr = $this->brand();
        $this->assertSame('Što kuhati ovaj tjedan: 5 jela iz akcija', $this->headline($hr, 5));
        $this->assertSame('Što kuhati ovaj tjedan: 2 jela iz akcija', $this->headline($hr, 2));

        $at = $this->recipeBrand('at');
        $this->assertSame('Was kochen diese Woche: 5 Rezepte im Angebot', $this->headline($at, 5));
    }

    public function test_the_recipe_source_command_makes_a_source_and_rules_that_are_all_off(): void
    {
        $brand = $this->brand();
        $deals = Source::factory()->for($brand)->create([
            'name' => 'listo', 'base_url' => 'https://api.test/v1/social-feed', 'secret' => 'tajna',
            'config' => ['query.country' => 'hr', 'query.min_discount' => 35],
        ]);
        SocialAccount::factory()->for($brand)->create(['platform' => Platform::FacebookPage, 'name' => 'FB']);
        SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok, 'name' => 'TikTok']);
        SocialAccount::factory()->for($brand)->create(['platform' => Platform::FacebookGroup, 'name' => 'Grupa']);

        $this->artisan('hub:recipe-source', ['brand' => 'uselisto'])->assertSuccessful();

        $source = $brand->sources()->where('name', 'listo-recepti')->sole();
        $this->assertFalse($source->enabled);
        $this->assertSame($deals->base_url, $source->base_url);
        // The panel's key/value field keeps the filters flat: `query.kind`, not `query` => [`kind`].
        $this->assertSame('recipe', $source->config['query.kind']);
        $this->assertSame('hr', $source->config['query.country']);
        $this->assertArrayNotHasKey('query.min_discount', $source->config, 'a discount floor is a deal\'s filter');

        $rules = $source->autoPublishRules()->get()->keyBy(fn (AutoPublishRule $rule): string => $rule->platform->value);
        $this->assertEqualsCanonicalizing(['fb_page', 'tiktok'], $rules->keys()->all(), 'a group is posted by hand and gets no rule');
        $this->assertSame('image', $rules['fb_page']->format->value);
        $this->assertSame('video', $rules['tiktok']->format->value);
        $this->assertTrue($rules->every(fn (AutoPublishRule $rule): bool => ! $rule->enabled && $rule->requires_approval && $rule->daily_cap === 1));

        // A second run makes nothing and says so.
        $this->artisan('hub:recipe-source', ['brand' => 'uselisto'])->assertFailed();
        $this->assertSame(1, $brand->sources()->where('name', 'listo-recepti')->count());
    }

    public function test_the_recipe_source_of_the_austrian_brand_is_named_for_it_and_dry_run_makes_nothing(): void
    {
        $brand = $this->recipeBrand('at');
        Source::factory()->for($brand)->create(['name' => 'listo-at', 'config' => ['query.country' => 'at']]);

        $this->artisan('hub:recipe-source', ['brand' => 'uselisto-at', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, $brand->sources()->where('name', 'like', 'listo-recepti%')->count());

        $this->artisan('hub:recipe-source', ['brand' => 'uselisto-at'])->assertSuccessful();
        $this->assertSame(1, $brand->sources()->where('name', 'listo-recepti-at')->count());
    }

    private function brand(array $over = []): Brand
    {
        return $this->recipeBrand('hr', [
            'timezone' => 'Europe/Zagreb',
            'posting_windows' => [['from' => '07:30', 'to' => '09:00'], ['from' => '12:00', 'to' => '13:30'], ['from' => '18:00', 'to' => '21:00']],
            'daily_post_limit' => 3,
            'auto_lineup' => true,
            'lineup_priority' => ['recipe', 'deal'],
            ...$over,
        ]);
    }

    private function source(Brand $brand): Source
    {
        $source = Source::factory()->for($brand)->create(['name' => 'listo-recepti', 'base_url' => 'https://site.test/api/social-feed', 'secret' => 'token']);
        SocialAccount::factory()->for($brand)->create();
        AutoPublishRule::query()->create([
            'source_id' => $source->id, 'platform' => Platform::FacebookPage, 'enabled' => true, 'requires_approval' => false,
            'delay_minutes' => 0, 'daily_cap' => null, 'format' => null, 'settings' => null,
        ]);

        return $source;
    }

    private function dish(Brand $brand, Source $source, int $index, int $daysLeft = 5, array $raw = []): ContentItem
    {
        $feed = $this->recipeFeedItem($index);
        $feed['id'] = "recipe:test-{$index}";
        $feed['url'] = "https://example.test/recept/{$index}";
        $feed['raw']['recipe'] = [...$feed['raw']['recipe'], ...$raw];

        return ContentItem::query()->create([
            ...\App\Sources\ContentItemData::fromFeedItem($feed)->toAttributes(),
            'source_id' => $source->id, 'brand_id' => $brand->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'published_at' => CarbonImmutable::now()->subHour(),
            'expires_at' => CarbonImmutable::now()->addDays($daysLeft),
        ]);
    }

    /**
     * An item of another kind in the same source (a leaflet is made from the feed, with the demo block its video needs).
     */
    private function otherKind(Brand $brand, Source $source, ContentKind $kind, string $externalId, int $priority = 3): ContentItem
    {
        $attributes = [
            'kind' => $kind,
            'external_id' => $externalId,
            'title' => $externalId,
            'url' => 'https://example.test/'.str_replace(':', '/', $externalId),
            'priority' => $priority,
            'published_at' => CarbonImmutable::now()->subHour(),
            'expires_at' => CarbonImmutable::now()->addDays(10),
        ];

        if ($kind === ContentKind::Catalog) {
            $attributes = [...\App\Sources\ContentItemData::fromFeedItem($this->catalogFeedItem())->toAttributes(), ...$attributes];
        }

        if ($kind === ContentKind::Catalog) {
            return ContentItem::query()->create([
                ...$attributes,
                'source_id' => $source->id, 'brand_id' => $brand->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }

        return ContentItem::factory()->for($source)->create($attributes);
    }

    private function headline(Brand $brand, int $count): string
    {
        $method = new ReflectionMethod(DigestBuilder::class, 'defaultHeadline');

        return $method->invoke(app(DigestBuilder::class), ContentKind::Recipe, $count, $brand);
    }
}
