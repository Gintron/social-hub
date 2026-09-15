<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use App\Rendering\ImageRenderer;
use App\Rendering\RemoteImageCache;
use App\Rendering\TemplateData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Whose offer the card is showing — the chain, the employer, the publisher. The feed states it the
 * same way for every kind (`subtitle` + `images[role=logo]`), and the card has to say it loudly
 * enough that the post does not read as if the brand were selling the goods itself.
 */
final class ProviderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A mark shaped like a wordmark (50×10, as SPAR and Konzum are) and one shaped like a badge
     * (12×12, as Lidl and Tommy are). The hub lays them out by that shape, so the tests need it.
     */
    private const WORDMARK = 'iVBORw0KGgoAAAANSUhEUgAAADIAAAAKCAIAAAB5dJomAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAJklEQVQ4jWN8zMDPMPgA00A7ADsYdRYpYNRZpIBRZ5ECRp1FCgAA04kBBpzNxAIAAAAASUVORK5CYII=';

    private const BADGE = 'iVBORw0KGgoAAAANSUhEUgAAAAwAAAAMCAIAAADZF8uwAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAF0lEQVQYlWN8zMDPQAgwEVQxqmgAFAEAuUMBCmEmg4kAAAAASUVORK5CYII=';

    /**
     * RemoteImageCache keeps real files for a week, so a test that reuses a URL would otherwise be
     * handed the picture an earlier run left behind.
     */
    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/cache/images'));
    }

    public function test_a_deal_gives_the_chain_a_band_of_its_own(): void
    {
        Http::fake();

        [$brand, $item] = $this->dealFrom('Kaufland');

        $card = $this->html($brand, 'kinds/deal-portrait', $item);

        $this->assertStringContainsString('AKCIJA U TRGOVINI', $card);
        $this->assertStringContainsString('<div class="name">Kaufland</div>', $card);
        // The old square chip fitted a wordmark by its width, leaving a few pixels of type.
        $this->assertStringNotContainsString('logo-chip', $card);
    }

    public function test_a_wordmark_is_sized_by_its_own_proportions(): void
    {
        [$brand, $item] = $this->dealFrom('Spar', logo: 'https://spar.hr/logo.png');

        $params = app(TemplateData::class)->forItem($item, $brand);
        $card = app(ImageRenderer::class)->html($brand, 'kinds/deal-portrait', $params);

        $this->assertStringStartsWith('data:image/png;base64,', (string) $params['provider']['logo']);
        $this->assertSame(5.0, $params['provider']['logo_ratio']);
        $this->assertStringContainsString('<img src="'.$params['provider']['logo'].'" alt="Spar">', $card);
        // A wide mark (SPAR and Konzum are about 5:1) has to grow sideways, not be boxed into a square.
        $this->assertStringContainsString('.provider img { height: 84px; width: auto;', $card);
        $this->assertStringContainsString('<div class="provider provider--lg">', $card);
        // With a wordmark the plaque does not also spell the name out: the mark already is the name.
        $this->assertStringNotContainsString('<div class="name">Spar</div>', $card);
    }

    public function test_a_square_badge_takes_the_height_it_cannot_take_in_width(): void
    {
        [$brand, $item] = $this->dealFrom('Lidl', logo: 'https://lidl.hr/badge.png', shape: self::BADGE);

        $params = app(TemplateData::class)->forItem($item, $brand);
        $card = app(ImageRenderer::class)->html($brand, 'kinds/deal-portrait', $params);

        $this->assertSame(1.0, $params['provider']['logo_ratio']);
        // At a wordmark's height a badge covers a fifth of the area, so it gets the taller box…
        $this->assertStringContainsString('class="provider provider--lg provider--compact"', $card);
        $this->assertStringContainsString('.provider--compact img { height: 116px;', $card);
        // … and the name beside it, the way a shop locks its own badge up with its name.
        $this->assertStringContainsString('<div class="name">Lidl</div>', $card);
    }

    public function test_an_svg_mark_is_measured_by_its_own_viewbox(): void
    {
        // Konzum ships its logo as an SVG, which getimagesize() cannot measure.
        Http::fake(['*' => Http::response(
            '<svg xmlns="http://www.w3.org/2000/svg" width="142" height="29" viewBox="0 0 142 29"><path d="M19 0l-4 4 4 4z"/></svg>',
            200,
            ['Content-Type' => 'image/svg+xml'],
        )]);

        $ratio = app(RemoteImageCache::class)->aspectRatio('https://konzum.hr/logo.svg');

        $this->assertNotNull($ratio);
        $this->assertEqualsWithDelta(142 / 29, $ratio, 0.001);
    }

    public function test_the_brands_own_logo_never_stands_in_for_the_shops(): void
    {
        Http::fake();

        [$brand, $item] = $this->dealFrom('Kaufland');
        $brand->forceFill(['logo_path' => 'brands/listo.png'])->save();

        $params = app(TemplateData::class)->forItem($item, $brand->refresh());

        // The hub passes the offer on, it does not sell it: its mark belongs in the footer only.
        $this->assertNull($params['provider']['logo']);
        $this->assertSame('Kaufland', $params['provider']['name']);
    }

    public function test_the_hook_slide_says_where_the_price_is_from(): void
    {
        Http::fake();

        [$brand, $item] = $this->dealFrom('Kaufland');

        $hook = $this->html($brand, 'kinds/hook-story', $item);

        $this->assertStringContainsString('<div class="name">Kaufland</div>', $hook);
        // It is said once: the plaque replaced the muted line under the title.
        $this->assertSame(1, mb_substr_count($hook, 'Kaufland'));
    }

    public function test_a_roundup_wears_one_chains_name_only_when_every_offer_is_its_own(): void
    {
        Http::fake();

        $brand = Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo']);
        $source = Source::factory()->for($brand)->create();
        $data = app(TemplateData::class);

        $kaufland = collect(['Kaufland', 'Kaufland'])->map(fn (string $chain): ContentItem => $this->deal($source, $chain));
        $mixed = collect(['Kaufland', 'Spar'])->map(fn (string $chain): ContentItem => $this->deal($source, $chain));

        $this->assertSame('Kaufland', $data->forDigest($kaufland, $brand, 'Top akcije')['provider']['name']);
        $this->assertNull($data->forDigest($mixed, $brand, 'Top akcije')['provider']['name']);

        $cover = app(ImageRenderer::class)->html($brand, 'kinds/digest-cover-portrait', $data->forDigest($kaufland, $brand, 'Top akcije'));
        $this->assertStringContainsString('<div class="name">Kaufland</div>', $cover);
    }

    public function test_a_job_card_keeps_the_employer_in_one_place(): void
    {
        Http::fake();

        $brand = Brand::factory()->create();
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Job,
            'title' => 'Konobar/ica',
            'subtitle' => 'Hotel Adriatic d.o.o.',
            'images' => [],
        ]);

        $card = $this->html($brand, 'kinds/job-portrait', $item);

        // Without a logo the hero stays clear: the employer is already named under the title.
        $this->assertStringNotContainsString('class="provider provider--hero"', $card);
        $this->assertSame(1, mb_substr_count($card, 'Hotel Adriatic d.o.o.'));
    }

    private function html(Brand $brand, string $templateKey, ContentItem $item): string
    {
        return app(ImageRenderer::class)->html($brand, $templateKey, app(TemplateData::class)->forItem($item, $brand));
    }

    /**
     * @return array{0: Brand, 1: ContentItem}
     */
    private function dealFrom(string $chain, ?string $logo = null, string $shape = self::WORDMARK): array
    {
        if ($logo !== null) {
            Http::fake([$logo => Http::response(base64_decode($shape), 200, ['Content-Type' => 'image/png'])]);
        }

        $brand = Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com']);

        return [$brand, $this->deal(Source::factory()->for($brand)->create(), $chain, $logo)];
    }

    private function deal(Source $source, string $chain, ?string $logo = null): ContentItem
    {
        return ContentItem::factory()->for($source)->for($source->brand)->create([
            'kind' => ContentKind::Deal,
            'title' => 'Gorenje kuhinjski robot',
            'subtitle' => $chain,
            'price' => ['current_cents' => 8499, 'old_cents' => 16999, 'discount_pct' => 50, 'currency' => 'EUR', 'unit_label' => null],
            'images' => $logo !== null ? [['url' => $logo, 'role' => 'logo']] : [],
        ]);
    }
}
