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
use Tests\TestCase;

/**
 * A quote's card: the words as the source sent them and whose they are, in the brand's colours.
 * What must be readable is asserted here; how the type is sized is the one rule worth pinning.
 */
final class QuoteFramesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_quote_is_told_as_its_card_and_the_brands_call_to_action(): void
    {
        $registry = app(TemplateRegistry::class);

        $this->assertSame(['kinds/quote-story', 'kinds/cta-story'], $registry->slidesFor(ContentKind::Quote, 'story'));
        $this->assertSame(['kinds/quote-portrait', 'kinds/cta-portrait'], $registry->slidesFor(ContentKind::Quote, 'portrait'));
        // The card, not the end card, is what a quote is rendered as when nothing else is asked.
        $this->assertSame('kinds/quote-square', $registry->defaultFor(ContentKind::Quote, 'square'));
        $this->assertSame('kinds/quote-story', $registry->storyFor(ContentKind::Quote));
        $this->assertArrayHasKey('kinds/quote-portrait', $registry->optionsFor(ContentKind::Quote));
        $this->assertArrayNotHasKey('kinds/quote-portrait', $registry->optionsFor(ContentKind::Deal));
    }

    public function test_the_words_and_whose_they_are_are_on_the_card_and_nothing_else_of_the_item(): void
    {
        $card = $this->html('kinds/quote-story', $this->quote([
            'facts' => [['label' => 'LOKACIJA', 'value' => 'SPLIT']],
            'badges' => ['Novo'],
            'price' => ['current_cents' => 149, 'currency' => 'EUR'],
        ]));

        $this->assertStringContainsString('<div class="text">Ljubi bližnjega svoga kao samoga sebe.</div>', $card);
        $this->assertStringContainsString('<div class="source">Mk 12,31</div>', $card);
        $this->assertStringContainsString('<div class="rule"></div>', $card);
        // Whose it is stays as written: "Iv 3,16" in capitals would read as another book.
        $this->assertDoesNotMatchRegularExpression('/\.quote \.source \{[^}]*text-transform/', $card);
        $this->assertStringNotContainsString('SPLIT', $card);
        $this->assertStringNotContainsString('Novo', $card);
        $this->assertStringNotContainsString('1,49', $card);
        $this->assertStringNotContainsString('class="hero"', $card);
    }

    public function test_the_brand_signs_the_card_with_its_name_and_address(): void
    {
        $card = $this->html('kinds/quote-portrait', $this->quote());

        $this->assertStringContainsString('<div class="site">rijec.test</div>', $card);
        $this->assertStringContainsString('background: #1e3a5f', $card);
    }

    public function test_a_quote_nobody_is_credited_with_has_no_rule_or_source_line(): void
    {
        $card = $this->html('kinds/quote-story', $this->quote(['subtitle' => null]));

        $this->assertStringNotContainsString('<div class="source">', $card);
        $this->assertStringNotContainsString('<div class="rule">', $card);
    }

    public function test_the_text_is_escaped_not_interpreted(): void
    {
        $card = $this->html('kinds/quote-story', $this->quote(['title' => '<script>alert(1)</script> Blago']));

        $this->assertStringNotContainsString('<script>alert', $card);
        $this->assertStringContainsString('&lt;script&gt;', $card);
    }

    public function test_the_type_steps_down_with_the_length_and_the_longest_text_the_contract_allows_still_has_a_size(): void
    {
        $sizes = [];

        foreach ([30, 100, 150, 230, 300] as $length) {
            $card = $this->html('kinds/quote-story', $this->quote(['title' => str_repeat('a', $length)]));
            preg_match('/\.quote \.text \{[^}]*font-size: (\d+)px/', $card, $found);
            $sizes[$length] = (int) $found[1];
        }

        $this->assertSame([30 => 96, 100 => 80, 150 => 68, 230 => 60, 300 => 54], $sizes);
    }

    public function test_the_smaller_frames_scale_the_same_steps_down(): void
    {
        $size = function (string $key): int {
            preg_match('/\.quote \.text \{[^}]*font-size: (\d+)px/', $this->html($key, $this->quote()), $found);

            return (int) $found[1];
        };

        $this->assertSame(96, $size('kinds/quote-story'));
        $this->assertSame(86, $size('kinds/quote-portrait'));
        $this->assertSame(75, $size('kinds/quote-square'));
    }

    private function html(string $key, ContentItem $item): string
    {
        return app(ImageRenderer::class)->html($item->brand, $key, app(TemplateData::class)->forItem($item, $item->brand));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function quote(array $overrides = []): ContentItem
    {
        $brand = Brand::factory()->create([
            'name' => 'Riječ',
            'site_url' => 'https://rijec.test',
            'colors' => ['primary' => '#1e3a5f', 'accent' => '#d4a017'],
        ]);

        return ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(array_replace([
            'kind' => ContentKind::Quote,
            'title' => 'Ljubi bližnjega svoga kao samoga sebe.',
            'subtitle' => 'Mk 12,31',
            'facts' => [],
            'badges' => [],
            'price' => null,
            'images' => [],
            'expires_at' => null,
        ], $overrides));
    }
}
