<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use App\Rendering\TemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The slides an item is told with as a carousel or a video. Checked as HTML: the screenshot is
 * Chromium's business, what goes on the slide is ours.
 */
final class SlideTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_kind_has_a_slide_set_in_both_orientations(): void
    {
        $registry = app(TemplateRegistry::class);

        foreach (ContentKind::cases() as $kind) {
            foreach (['portrait', 'story'] as $orientation) {
                $keys = $registry->slidesFor($kind, $orientation);

                $this->assertGreaterThanOrEqual(2, count($keys), "{$kind->value} {$orientation}");

                foreach ($keys as $key) {
                    $this->assertStringEndsWith("-{$orientation}", $key);
                    $registry->get($key);
                }
            }
        }

        $this->assertSame(['kinds/hook-story', 'kinds/job-story', 'kinds/cta-story'], $registry->slidesFor(ContentKind::Job, 'story'));
        // The hook and end card come after the kinds, so a kind's own card stays its default.
        $this->assertSame('kinds/job-portrait', $registry->defaultFor(ContentKind::Job, 'portrait'));
    }

    public function test_the_hook_leads_with_the_pay_and_the_end_card_with_the_brands_call_to_action(): void
    {
        Http::fake();

        $brand = Brand::factory()->create([
            'slug' => 'studentski-poslovi',
            'name' => 'Studentski poslovi',
            'site_url' => 'https://studentski-poslovi.hr',
            'voice' => ['cta' => 'Prijavi se na studentski-poslovi.hr'],
        ]);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Job,
            'title' => 'Asistent direktora hotela',
            'facts' => [['label' => 'LOKACIJA', 'value' => 'LOVRAN'], ['label' => 'SATNICA', 'value' => '7.00 - 8.00 €/H']],
            'price' => ['current_cents' => 700, 'unit_label' => '€/H'],
            'badges' => ['Smještaj'],
            'images' => [],
        ]);

        $renderer = app(ImageRenderer::class);
        $params = app(TemplateData::class)->forItem($item, $brand);

        $hook = $renderer->html($brand, 'kinds/hook-story', $params);
        $this->assertStringContainsString('7.00 - 8.00 €/H', $hook);
        $this->assertStringContainsString('SATNICA', $hook);
        $this->assertStringContainsString('Lovran', $hook);
        $this->assertStringContainsString('Smještaj', $hook);
        $this->assertStringNotContainsString('class="footer"', $hook, 'na 9:16 dno pokriva sučelje aplikacije');

        $this->assertStringContainsString('class="footer"', $renderer->html($brand, 'kinds/hook-portrait', $params));

        $end = $renderer->html($brand, 'kinds/cta-portrait', $params);
        $this->assertStringContainsString('Prijavi se na studentski-poslovi.hr', $end);
        $this->assertStringContainsString('studentski-poslovi.hr', $end);
        $this->assertStringNotContainsString('class="pitch"', $end, 'brend bez rečenice ne dobiva prazan redak');
        $this->assertStringNotContainsString('class="note"', $end);
    }

    public function test_the_end_card_says_what_the_brand_does_and_where_to_get_it(): void
    {
        Http::fake();

        $brand = Brand::factory()->create([
            'slug' => 'uselisto',
            'name' => 'Listo',
            'site_url' => 'https://uselisto.com',
            'voice' => [
                'cta' => 'Preuzmi Listo besplatno',
                'pitch' => 'Svi letci na jednom mjestu: dodirni proizvod i on je na listi.',
                'cta_note' => 'App Store i Google Play · 20 dana bez kartice',
            ],
        ]);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Deal,
            'title' => 'Tuna u maslinovom ulju',
            'subtitle' => 'Konzum',
            'price' => ['current_cents' => 100, 'old_cents' => 189, 'discount_pct' => 47, 'currency' => 'EUR', 'unit_label' => null],
            'images' => [],
        ]);

        $end = app(ImageRenderer::class)->html($brand, 'kinds/cta-story', app(TemplateData::class)->forItem($item, $brand));

        $this->assertStringContainsString('<div class="pitch">Svi letci na jednom mjestu: dodirni proizvod i on je na listi.</div>', $end);
        $this->assertStringContainsString('<div class="note">App Store i Google Play · 20 dana bez kartice</div>', $end);
        $this->assertLessThan(mb_strpos($end, 'class="where"'), mb_strpos($end, 'class="pitch"'), 'razlog prije adrese');
    }

    public function test_a_deal_hook_strikes_the_old_price_and_a_filled_logo_keeps_its_colours(): void
    {
        Http::fake();

        $brand = Brand::factory()->create([
            'slug' => 'uselisto',
            'name' => 'Listo',
            'site_url' => 'https://uselisto.com',
            'colors' => ['primary' => '#2F6B45', 'logo_footer' => 'original'],
        ]);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Deal,
            'title' => 'Gorenje Kuhinjski robot',
            'subtitle' => 'Kaufland',
            'facts' => [['label' => 'VRIJEDI DO', 'value' => '26.9.2026.']],
            'price' => ['current_cents' => 8499, 'old_cents' => 16999, 'discount_pct' => 50, 'currency' => 'EUR', 'unit_label' => null],
            'badges' => ['−50 %'],
            'images' => [],
        ]);

        $renderer = app(ImageRenderer::class);
        $params = app(TemplateData::class)->forItem($item, $brand);

        $hook = $renderer->html($brand, 'kinds/hook-portrait', $params);
        $this->assertStringContainsString('84,99 €', $hook);
        $this->assertStringContainsString('<div class="figure-old">169,99 €</div>', $hook);
        $this->assertStringNotContainsString('invert(1)', $hook, 'ispunjen logo ne smije postati bijela mrlja');

        $brand->forceFill(['colors' => ['primary' => '#2F6B45']])->save();
        $this->assertStringContainsString('invert(1)', $renderer->html($brand->refresh(), 'kinds/hook-portrait', $params));
    }

    public function test_a_roundup_cover_leads_with_its_deepest_discount_and_skips_empty_tiles(): void
    {
        Http::fake();

        $brand = Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com']);
        $source = Source::factory()->for($brand)->create();
        $items = collect([40, 60])->map(fn (int $discount): ContentItem => ContentItem::factory()->for($source)->for($brand)->create([
            'kind' => ContentKind::Deal,
            'price' => ['current_cents' => 199, 'old_cents' => 499, 'discount_pct' => $discount, 'currency' => 'EUR', 'unit_label' => null],
            'images' => [],
        ]));

        $params = app(TemplateData::class)->forDigest($items, $brand, 'Top 2 akcija u Kauflandu', 'Listo');
        $cover = app(ImageRenderer::class)->html($brand, 'kinds/digest-cover-story', $params);

        $this->assertStringContainsString('Top 2 akcija u Kauflandu', $cover);
        $this->assertStringContainsString('do −60 %', $cover);
        $this->assertSame([], $params['thumbnails'], 'stavke bez slike ne ostavljaju prazne pločice');
        $this->assertStringNotContainsString('<div class="thumbs">', $cover);
    }
}
