<?php

declare(strict_types=1);

namespace Tests\Feature\Pinterest;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Pinterest\ChromiumPinRenderer;
use App\Pinterest\PinBuilder;
use App\Pinterest\PinImageRenderer;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;
use Tests\Support\MakesRecipeItems;
use Tests\TestCase;

/**
 * The weekly Pinterest pins of the Austrian brand and the feeds Pinterest reads (docs/pinterest.md). The picture itself is drawn by Chromium, which the
 * test image does not have: a fake renderer stands in, and the HTML of the template is checked on its own.
 */
final class PinterestPinsTest extends TestCase
{
    use MakesRecipeItems;
    use RefreshDatabase;

    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAwAAAAMCAIAAADZF8uwAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAF0lEQVQYlWN8zMDPQAgwEVQxqmgAFAEAuUMBCmEmg4kAAAAASUVORK5CYII=';

    private Brand $brand;

    /** @var list<array<string, mixed>> What the fake renderer was asked to draw. */
    private array $drawn = [];

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/cache/images'));
        Storage::fake('public');
        Http::fake(fn () => Http::response((string) base64_decode(self::PIXEL), 200, ['Content-Type' => 'image/png']));
        $this->drawn = [];

        $this->app->bind(PinImageRenderer::class, fn () => new class($this) implements PinImageRenderer
        {
            private int $n = 0;

            public function __construct(private readonly PinterestPinsTest $test) {}

            public function render(Brand $brand, array $viewModel): MediaAsset
            {
                $this->test->drawn($viewModel);
                $this->n++;
                $path = "media/{$brand->slug}/pin-".uniqid().'.jpg';
                Storage::disk('public')->put($path, 'jpeg');

                return MediaAsset::query()->create([
                    'brand_id' => $brand->id, 'template_key' => ChromiumPinRenderer::TEMPLATE, 'params' => [], 'width' => 1000, 'height' => 1500,
                    'format' => 'jpg', 'disk' => 'public', 'path' => $path, 'bytes' => 4, 'checksum' => hash('sha256', $path),
                ]);
            }
        });

        $this->brand = $this->recipeBrand('at');
        // The five dishes of the fixture: eurospar, billa (25 min, the dearest saving), billa-plus, interspar, spar (25 min).
        foreach (range(0, 4) as $i) {
            $this->recipeItem($this->brand, $this->recipeFeedItem($i, 'at'), [], 'at');
        }
    }

    /** @param array<string, mixed> $model */
    public function drawn(array $model): void
    {
        $this->drawn[] = $model;
    }

    public function test_the_week_gets_one_pin_for_each_pin_that_has_a_dish_and_none_for_the_rest(): void
    {
        $pins = collect($this->builder()->buildWeek($this->brand))->keyBy(fn (MediaAsset $a): string => $this->pin($a)['pin']);

        // billa, spar and interspar have a dish, and so has wochenplan (any shop). hofer, penny and lidl have none this week, and schnell (≤ 25 minutes)
        // has none left: the only two dishes that quick are billa's and spar's, and their own pins have taken them.
        $this->assertEqualsCanonicalizing(['billa', 'spar', 'interspar', 'wochenplan'], $pins->keys()->all());
    }

    public function test_a_pin_takes_the_dish_with_the_biggest_saving_of_its_shop_and_never_one_used_by_another_pin_of_the_week(): void
    {
        $pins = collect($this->builder()->buildWeek($this->brand))->keyBy(fn (MediaAsset $a): string => $this->pin($a)['pin']);
        $itemOf = fn (string $pin): ContentItem => ContentItem::query()->findOrFail($this->pin($pins[$pin])['item_id']);

        $this->assertSame('billa', data_get($itemOf('billa')->raw, 'recipe.chain'));
        $this->assertSame('spar', data_get($itemOf('spar')->raw, 'recipe.chain'));

        $used = $pins->map(fn (MediaAsset $a): int => $this->pin($a)['item_id']);
        $this->assertCount($used->count(), $used->unique(), 'two pins of one week show one dish');

        $this->assertFalse($pins->has('schnell'));
    }

    public function test_a_pin_says_nothing_that_ends_and_links_to_a_page_that_stays_on_the_claimed_domain(): void
    {
        $pin = $this->pin(collect($this->builder()->buildWeek($this->brand))->first(fn (MediaAsset $a): bool => $this->pin($a)['pin'] === 'billa'));

        $this->assertSame('https://uselisto.com/at/rezepte/billa?utm_source=pinterest&utm_medium=social&utm_campaign=pin-angebote', $pin['link']);
        $this->assertSame('uselisto.com', parse_url($pin['link'], PHP_URL_HOST), 'Pinterest accepts only links on the claimed domain');
        $this->assertSame('angebote', $pin['board']);
        $this->assertStringContainsString('KI-Illustration', $pin['description']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $pin['title'].$pin['description'], 'no price, date or count: a pin lives for months');
    }

    public function test_running_again_in_the_same_week_makes_nothing_and_the_next_week_makes_new_pins_with_new_dishes(): void
    {
        $first = $this->builder()->buildWeek($this->brand);
        $this->assertNotEmpty($first);
        $this->assertSame([], $this->builder()->buildWeek($this->brand), 'the command may be run twice');
        $this->assertSame(count($first), MediaAsset::query()->count());

        // A week later the same dishes are still on offer (the fixture's expiry is moved): a dish is not pinned twice by the same pin, so only the pins
        // that have another dish left make a new pin.
        ContentItem::query()->update(['expires_at' => now()->addDays(20)]);
        // Another billa dish arrives with the new week's leaflet.
        $next = $this->recipeFeedItem(1, 'at');
        $next['id'] = 'recipe:neu0000001';
        $next['title'] = 'Lachs, andere Woche';
        $next['raw']['recipe']['recipe_id'] = 'neu0000001';
        $this->recipeItem($this->brand, $next, ['expires_at' => now()->addDays(20)], 'at');
        $this->travel(7)->days();
        $second = $this->builder()->buildWeek($this->brand);

        $this->assertNotEmpty($second);
        $firstItems = collect($first)->map(fn (MediaAsset $a): int => $this->pin($a)['item_id']);
        foreach ($second as $asset) {
            $pin = $this->pin($asset);
            $same = $first ? collect($first)->first(fn (MediaAsset $a): bool => $this->pin($a)['pin'] === $pin['pin']) : null;
            $this->assertTrue($same === null || $this->pin($same)['item_id'] !== $pin['item_id'], "{$pin['pin']} repeated its dish");
            $this->assertNotSame($pin['week'], $this->pin($first[0])['week']);
        }
        $this->assertTrue($firstItems->isNotEmpty());
    }

    public function test_a_dish_whose_offers_have_ended_is_not_pinned(): void
    {
        ContentItem::query()->update(['expires_at' => now()->subHour()]);

        $this->assertSame([], $this->builder()->buildWeek($this->brand));
    }

    public function test_a_brand_without_pinterest_settings_makes_nothing(): void
    {
        $other = $this->recipeBrand('hr');
        $this->recipeItem($other);

        $this->assertSame([], $this->builder()->buildWeek($other));
    }

    public function test_the_feed_is_rss_with_the_picture_as_enclosure_and_only_the_boards_own_pins(): void
    {
        $this->builder()->buildWeek($this->brand);

        $response = $this->get('/pinterest/uselisto-at/angebote.xml');
        $response->assertOk();
        $this->assertStringStartsWith('application/rss+xml', (string) $response->headers->get('Content-Type'));

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml, 'the feed is well-formed XML');
        $this->assertSame('2.0', (string) $xml['version']);
        $this->assertStringContainsString('Rezepte mit BILLA-, SPAR- und Hofer-Angeboten', (string) $xml->channel->title);

        $titles = $this->titles($xml);
        // Board `angebote` is billa, spar and hofer: two of them have a dish this week.
        $this->assertEqualsCanonicalizing(['Günstig kochen mit dem BILLA-Prospekt', 'Was kochen mit den SPAR-Angeboten?'], $titles);

        $item = $xml->channel->item[0];
        $this->assertStringStartsWith('https://uselisto.com/at/rezepte/', (string) $item->link);
        $this->assertSame('image/jpeg', (string) $item->enclosure['type']);
        $this->assertStringStartsWith(Storage::disk('public')->url('media/uselisto-at/pin-'), (string) $item->enclosure['url']);
        $this->assertStringContainsString('listo-pin-', (string) $item->guid);
        $this->assertNotSame('', (string) $item->pubDate);
        $this->assertStringContainsString('KI-Illustration', (string) $item->description);
    }

    public function test_the_feed_drops_pins_older_than_the_configured_days_and_a_pin_of_another_board_is_not_in_it(): void
    {
        $this->builder()->buildWeek($this->brand);
        $old = MediaAsset::query()->where('params->pinterest->pin', 'billa')->first();
        $old->forceFill(['created_at' => now()->subDays(90)])->save();

        $xml = simplexml_load_string($this->get('/pinterest/uselisto-at/angebote.xml')->getContent());
        $this->assertSame(['Was kochen mit den SPAR-Angeboten?'], $this->titles($xml));

        $plan = simplexml_load_string($this->get('/pinterest/uselisto-at/wochenplan.xml')->getContent());
        $this->assertEqualsCanonicalizing(['Wochenplan mit den INTERSPAR-Angeboten', 'Wochenplan günstig: Was kochen diese Woche?'], $this->titles($plan));

        $quick = simplexml_load_string($this->get('/pinterest/uselisto-at/schnell.xml')->getContent());
        $this->assertSame([], $this->titles($quick), 'a board with no pin is an empty, valid feed');
    }

    public function test_only_a_configured_board_of_a_known_brand_is_a_feed(): void
    {
        $this->get('/pinterest/uselisto-at/nepoznato.xml')->assertNotFound();
        $this->get('/pinterest/nepoznat-brend/angebote.xml')->assertNotFound();
        $this->get('/pinterest/uselisto/angebote.xml')->assertNotFound();
    }

    public function test_the_command_makes_the_pins_and_says_how_many(): void
    {
        $this->assertSame(0, Artisan::call('hub:pinterest-pins'));
        $this->assertStringContainsString('uselisto-at: 4 novih pinova', Artisan::output());
        $this->assertSame(0, Artisan::call('hub:pinterest-pins'));
        $this->assertStringContainsString('uselisto-at: 0 novih pinova', Artisan::output());

        $this->assertSame(1, Artisan::call('hub:pinterest-pins', ['brand' => 'nepoznat']));
    }

    public function test_it_is_scheduled_weekly_on_thursday_morning(): void
    {
        Artisan::call('schedule:list');

        $this->assertMatchesRegularExpression('/30\s+\d+\s+\*\s+\*\s+4\s+php artisan hub:pinterest-pins/', Artisan::output());
    }

    public function test_the_template_prints_the_pins_words_the_dish_and_its_label_and_nothing_that_ends(): void
    {
        $item = ContentItem::query()->where('brand_id', $this->brand->id)->first();
        $model = app(TemplateData::class)->forItem($item, $this->brand, ['pin' => ['eyebrow' => 'BILLA-Prospekt', 'headline' => 'Günstig kochen mit dem BILLA-Prospekt']]);
        $html = app(ImageRenderer::class)->html($this->brand, ChromiumPinRenderer::TEMPLATE, $model);

        foreach (['BILLA-Prospekt', 'Günstig kochen mit dem BILLA-Prospekt', 'KI-Illustration', 'data:image/png;base64,', 'uselisto.com'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        // The dish's offers, sums and dates end on Wednesday; the pin does not say them.
        foreach (['€', '15,26', '2,70', '18.11', 'Zutaten im Angebot'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertSame([], app(\App\Rendering\TemplateRegistry::class)->get(ChromiumPinRenderer::TEMPLATE)['kinds'], 'never offered as a slide of a post');
    }

    /** @return array<string, mixed> */
    private function pin(MediaAsset $asset): array
    {
        return (array) $asset->params['pinterest'];
    }

    /** @return list<string> */
    private function titles(SimpleXMLElement $feed): array
    {
        return array_map(fn ($title): string => (string) $title, $feed->xpath('/rss/channel/item/title') ?: []);
    }

    private function builder(): PinBuilder
    {
        return app(PinBuilder::class);
    }
}
