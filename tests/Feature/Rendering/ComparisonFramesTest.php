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
 * A comparison's hook and ranking. What has to be readable is asserted here; where it goes is ComparisonFrameTest's.
 */
final class ComparisonFramesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_row_is_a_bar_as_long_as_its_figure_and_the_cheapest_is_the_shortest_and_yellow(): void
    {
        $card = $this->html('kinds/comparison-story', $this->comparison());

        // 9,98 €/kg against the dearest 16,23 €/kg is 61 %: the bar of a shop is never shorter than its name and figure need.
        $this->assertStringContainsString('style="width: calc(64% + 4px);"', $card);
        $this->assertStringContainsString('style="width: calc(74% + 4px);"', $card);
        $this->assertStringContainsString('style="width: calc(100% + 4px);"', $card);
        $this->assertStringContainsString('<div class="row first">', $card);
        $this->assertSame(1, mb_substr_count($card, 'NAJJEFTINIJE'));
    }

    public function test_a_ranking_without_plain_numbers_is_a_list_of_equal_rows(): void
    {
        $card = $this->html('kinds/comparison-story', $this->comparison([
            ['label' => 'Lidl', 'value' => '9,98 €/kg'], ['label' => 'Spar', 'value' => 'od 11,98 €/kg'],
        ]));

        $this->assertSame(2, mb_substr_count($card, 'style="width: calc(100% + 4px);"'));
        $this->assertStringContainsString('od 11,98 €/kg', $card);
    }

    public function test_the_story_leaves_the_footer_out_because_the_apps_interface_covers_it(): void
    {
        $item = $this->comparison();

        foreach (['kinds/comparison-hook-story', 'kinds/comparison-story'] as $key) {
            $this->assertStringNotContainsString('class="footer"', $this->html($key, $item));
        }
        foreach (['kinds/comparison-hook-portrait', 'kinds/comparison-portrait', 'kinds/comparison-hook-square', 'kinds/comparison-square'] as $key) {
            $this->assertStringContainsString('class="footer"', $this->html($key, $item));
        }
    }

    public function test_a_square_shows_four_rows_and_the_other_formats_five(): void
    {
        $facts = array_map(fn (string $chain, int $i): array => ['label' => $chain, 'value' => number_format(9.98 + $i, 2, ',', '').' €/kg'], ['Lidl', 'Spar', 'Konzum', 'Kaufland', 'Plodine'], range(0, 4));
        $item = $this->comparison($facts);

        $this->assertSame(5, mb_substr_count($this->html('kinds/comparison-story', $item), '<div class="row '));
        $this->assertSame(4, mb_substr_count($this->html('kinds/comparison-square', $item), '<div class="row '));
    }

    public function test_the_hook_falls_back_to_a_price_written_out_when_the_figure_is_not_a_price(): void
    {
        $hook = $this->html('kinds/comparison-hook-story', $this->comparison([
            ['label' => 'Lidl', 'value' => 'od 9,98 €/kg'], ['label' => 'Spar', 'value' => '11,98 €/kg'],
        ]));

        $this->assertStringContainsString('<span class="n n--text">od 9,98 €/kg</span>', $hook);
    }

    private function html(string $key, ContentItem $item): string
    {
        return app(ImageRenderer::class)->html($item->brand, $key, app(TemplateData::class)->forItem($item, $item->brand));
    }

    /**
     * @param  list<array{label: string, value: string}>|null  $facts
     */
    private function comparison(?array $facts = null): ContentItem
    {
        Http::fake();

        $brand = Brand::factory()->create(['name' => 'Listo', 'site_url' => 'https://uselisto.com']);

        return ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Comparison,
            'title' => 'Kava u ovotjednim letcima: cijena po kilogramu',
            'subtitle' => null,
            'price' => null,
            'facts' => $facts ?? [
                ['label' => 'Lidl', 'value' => '9,98 €/kg'],
                ['label' => 'Spar', 'value' => '11,98 €/kg'],
                ['label' => 'Konzum', 'value' => '16,23 €/kg'],
            ],
            'badges' => ['3 trgovine'],
            'cta' => ['label' => 'Usporedi u Listu', 'url' => 'https://uselisto.com/trazi?q=kava&sort=unit'],
            'raw' => ['rows' => []],
            'images' => [],
            'expires_at' => '2026-09-27 23:59:59',
        ]);
    }
}
