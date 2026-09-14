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
    }
}
