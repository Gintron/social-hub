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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
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

    /** @var list<string> Titles of the dishes the fake renderer cannot draw (a picture that does not download, Chromium that times out). */
    private array $undrawable = [];

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/cache/images'));
        Storage::fake('public');
        Http::fake(fn () => Http::response((string) base64_decode(self::PIXEL), 200, ['Content-Type' => 'image/png']));
        $this->drawn = [];
        $this->undrawable = [];

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

        if (in_array($model['title'] ?? null, $this->undrawable, true)) {
            throw new RuntimeException('Chromium timed out');
        }
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
        // The dish's name does not end with its offers, and Pinterest search matches on it.
        $this->assertSame('Lachs mit Zitronenobers und Gurke – Günstig kochen mit dem BILLA-Prospekt', $pin['title']);
        $this->assertStringStartsWith('Lachs mit Zitronenobers und Gurke. Jede Woche neue Rezepte aus den Angeboten von BILLA', $pin['description']);
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

    public function test_a_dish_that_cannot_be_drawn_gives_its_pin_to_the_next_dish_and_does_not_stop_the_other_pins(): void
    {
        // The dearest billa dish is the pin's first choice; billa has no other dish, so that pin fails, but the pins after it are made all the same.
        $this->undrawable = ['Lachs mit Zitronenobers und Gurke'];
        $failed = [];

        $pins = collect($this->builder()->buildWeek($this->brand, null, $failed))->keyBy(fn (MediaAsset $a): string => $this->pin($a)['pin']);

        $this->assertFalse($pins->has('billa'));
        $this->assertEqualsCanonicalizing(['spar', 'interspar', 'wochenplan'], $pins->keys()->all());
        // `schnell` (≤ 25 minutes) wants the same dish, now that billa's pin has not taken it, and fails on it too: both are reported.
        $this->assertSame(['billa: Chromium timed out', 'schnell: Chromium timed out'], $failed);
        $this->assertSame(1, Artisan::call('hub:pinterest-pins'), 'a pin that could not be made is a failed run, though the others were');
        $this->assertStringContainsString('billa: Chromium timed out', Artisan::output());
    }

    public function test_a_pin_falls_back_to_the_next_best_dish_of_its_kind_when_the_first_cannot_be_drawn(): void
    {
        // `wochenplan` takes any shop's dish: the dearest unused one is eurospar's (270 after billa's 326, which the billa pin took).
        $this->undrawable = ['Hendlfilet mit Spinat und Erdäpfeln'];
        $failed = [];

        $pins = collect($this->builder()->buildWeek($this->brand, null, $failed))->keyBy(fn (MediaAsset $a): string => $this->pin($a)['pin']);

        $this->assertSame([], $failed, 'the pin was made, from the dish after the broken one');
        $this->assertTrue($pins->has('wochenplan'));
        $this->assertSame('billa-plus', data_get(ContentItem::query()->findOrFail($this->pin($pins['wochenplan'])['item_id'])->raw, 'recipe.chain'));
    }

    public function test_the_command_fails_and_logs_when_the_week_has_no_pin_at_all(): void
    {
        Log::spy();
        $this->undrawable = ContentItem::query()->pluck('title')->all();

        $this->assertSame(1, Artisan::call('hub:pinterest-pins'));
        $this->assertStringContainsString('nema nijednog pina', Artisan::output());
        Log::shouldHaveReceived('error')->with('hub.pinterest.no_pins_this_week', Mockery::type('array'))->once();
    }

    public function test_a_dish_with_no_end_date_is_still_on_offer_and_can_be_pinned(): void
    {
        ContentItem::query()->update(['expires_at' => null]);

        $this->assertNotEmpty($this->builder()->buildWeek($this->brand));
    }

    public function test_a_dish_whose_page_was_found_gone_is_not_pinned(): void
    {
        ContentItem::query()->update(['link_dead_at' => now()]);

        $this->assertSame([], $this->builder()->buildWeek($this->brand));
    }

    public function test_a_brand_without_an_absolute_site_url_makes_no_pin_and_says_why(): void
    {
        $this->brand->update(['site_url' => '']);

        try {
            $this->builder()->buildWeek($this->brand->refresh());
            $this->fail('a pin with a relative link would be skipped by Pinterest');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('site_url', $e->getMessage());
        }
        $this->assertSame(0, MediaAsset::query()->count(), 'no picture is drawn for a pin that cannot be published');
        $this->assertSame(1, Artisan::call('hub:pinterest-pins'));
    }

    public function test_the_words_the_hub_adds_follow_the_brands_language(): void
    {
        $croatian = $this->recipeBrand('hr', ['slug' => 'uselisto-hr']);
        config(['pinterest.brands.uselisto-hr' => [...config('pinterest.brands.uselisto-at'), 'pins' => [config('pinterest.brands.uselisto-at.pins.0')]]]);
        $this->recipeItem($croatian, $this->recipeFeedItem(1, 'at'));

        $made = $this->builder()->buildWeek($croatian);

        $this->assertCount(1, $made);
        $description = $this->pin($made[0])['description'];
        $this->assertStringContainsString('Svaki tjedan novi recepti iz akcija trgovine BILLA', $description);
        $this->assertStringNotContainsString('Jede Woche', $description);
        $this->assertStringContainsString('Slika jela je AI ilustracija.', $description);

        $this->get('/pinterest/uselisto-hr/angebote.xml')->assertOk()->assertSee('<language>hr-HR</language>', false);
    }

    public function test_the_feed_states_the_stored_size_of_the_picture_as_the_enclosures_length(): void
    {
        $this->builder()->buildWeek($this->brand);

        $xml = simplexml_load_string($this->get('/pinterest/uselisto-at/angebote.xml')->getContent());
        $this->assertSame('4', (string) $xml->channel->item[0]->enclosure['length'], 'the stored size of the picture (the fake renderer stored 4)');
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
        $this->assertEqualsCanonicalizing(['Lachs mit Zitronenobers und Gurke – Günstig kochen mit dem BILLA-Prospekt', 'Hendlfilet in Rahm mit Gemüse – Was kochen mit den SPAR-Angeboten?'], $titles);

        $item = $xml->channel->item[0];
        $this->assertSame('de-AT', (string) $xml->channel->language);
        $this->assertStringContainsString('Neue Pins für das Board', (string) $xml->channel->description);
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
        $this->assertSame(['Hendlfilet in Rahm mit Gemüse – Was kochen mit den SPAR-Angeboten?'], $this->titles($xml));

        $plan = simplexml_load_string($this->get('/pinterest/uselisto-at/wochenplan.xml')->getContent());
        $this->assertEqualsCanonicalizing(['Weißkraut-Erdäpfel-Topf – Wochenplan mit den INTERSPAR-Angeboten', 'Hendlfilet mit Spinat und Erdäpfeln – Wochenplan günstig: Was kochen diese Woche?'], $this->titles($plan));

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
