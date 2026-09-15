<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertStringContainsString('<img src="'.$params['provider']['logo'].'" alt="Spar">', $card);
        // A wide mark (SPAR and Konzum are about 5:1) has to grow sideways, not be boxed into a square.
        $this->assertStringContainsString('.provider img { height: 84px; width: auto;', $card);
        // With a logo the plaque does not also spell the name out: a wordmark already is the name.
        $this->assertStringNotContainsString('<div class="name">Spar</div>', $card);
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
    private function dealFrom(string $chain, ?string $logo = null): array
    {
        if ($logo !== null) {
            Http::fake([$logo => Http::response(base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
            ), 200, ['Content-Type' => 'image/png'])]);
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
