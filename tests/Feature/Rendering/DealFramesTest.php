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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A deal's hook and card: the shop, the leaflet picture, and one block with the name and the price.
 * What the shopper has to be able to read is asserted here; how it is laid out is DealFrameTest's.
 */
final class DealFramesTest extends TestCase
{
    use RefreshDatabase;

    /** A 640×437 picture, the shape of a leaflet crop, as a real PNG so the frame can measure it. */
    private const CROP = 'https://catalog.test/crops/brancin.png';

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/cache/images'));
    }

    public function test_the_hook_names_the_shop_stamps_the_discount_and_puts_name_and_price_on_one_block(): void
    {
        $hook = $this->html('kinds/deal-hook-story', $this->deal(['title' => 'BRANCIN OČIŠĆENI SVJEŽI']));

        $this->assertStringContainsString('AKCIJA U TRGOVINI', $hook);
        $this->assertStringContainsString('<div class="name">Konzum</div>', $hook);
        $this->assertStringContainsString('<div class="stamp"', $hook);
        $this->assertStringContainsString('−30%', $hook);
        // A shouted title is set as a title; the price is one figure with its old price struck beside it.
        $this->assertStringContainsString('Brancin Očišćeni Svježi', $hook);
        $this->assertStringContainsString('13,19</span><span class="c">€</span>', $hook);
        $this->assertStringContainsString('<span class="o">18,99 €</span>', $hook);
        // The picture is the leaflet tile, shown as it is: cut at the bottom, never stretched over the frame.
        $this->assertStringContainsString('leaflet__img leaflet__img--cut', $hook);
        $this->assertStringNotContainsString('class="hero"', $hook);
    }

    public function test_the_card_adds_the_facts_a_shopper_checks_and_the_end_date_when_no_fact_says_it(): void
    {
        $card = $this->html('kinds/deal-story', $this->deal(['facts' => [['label' => 'NAJNIŽA U 30 DANA', 'value' => '10,62 €'], ['label' => 'VRIJEDI DO', 'value' => '8.9.2026.']]]));

        $this->assertStringContainsString('NAJNIŽA U 30 DANA', $card);
        $this->assertStringContainsString('8.9.2026.', $card);

        $card = $this->html('kinds/deal-story', $this->deal(['facts' => [['label' => 'NAJNIŽA U 30 DANA', 'value' => '10,62 €']], 'expires_at' => now()->addDays(9)]));

        $this->assertStringContainsString('NAJNIŽA U 30 DANA', $card);
        $this->assertStringContainsString('VRIJEDI DO', $card);
    }

    public function test_a_direct_video_does_not_stamp_the_discount_a_third_time_on_the_card(): void
    {
        $item = $this->deal([], voice: ['video_style' => 'direct']);

        $this->assertStringContainsString('<div class="stamp"', $this->html('kinds/deal-hook-story', $item));
        $this->assertStringNotContainsString('<div class="stamp"', $this->html('kinds/deal-story', $item));
        // Outside a direct video, and on a post, the card keeps it.
        $this->assertStringContainsString('<div class="stamp"', $this->html('kinds/deal-portrait', $item));
    }

    public function test_only_the_story_leaves_the_footer_out_because_the_apps_interface_covers_it(): void
    {
        $item = $this->deal();

        $this->assertStringNotContainsString('class="footer"', $this->html('kinds/deal-story', $item));
        $this->assertStringNotContainsString('class="footer"', $this->html('kinds/deal-hook-story', $item));
        $this->assertStringContainsString('class="footer"', $this->html('kinds/deal-portrait', $item));
        $this->assertStringContainsString('class="footer"', $this->html('kinds/deal-hook-square', $item));
    }

    public function test_a_deal_without_a_picture_still_has_its_price_and_stamp(): void
    {
        $hook = $this->html('kinds/deal-hook-story', $this->deal(image: false));

        $this->assertStringNotContainsString('class="deal-media"', $hook);
        $this->assertStringContainsString('class="deal-emoji"', $hook);
        $this->assertStringContainsString('stamp stamp--offer', $hook);
        $this->assertStringContainsString('13,19</span>', $hook);
    }

    public function test_a_price_that_is_more_than_a_price_is_printed_as_the_source_wrote_it(): void
    {
        $hook = $this->html('kinds/deal-hook-story', $this->deal(['facts' => [['label' => 'CIJENA', 'value' => 'od 13,19 €']]]));

        $this->assertStringContainsString('<span class="n n--text">od 13,19 €</span>', $hook);
    }

    public function test_an_offer_without_a_discount_shows_its_badges_instead_of_a_stamp(): void
    {
        $hook = $this->html('kinds/deal-hook-story', $this->deal([
            'price' => ['current_cents' => 1319, 'old_cents' => null, 'discount_pct' => null, 'currency' => 'EUR', 'unit_label' => null],
            'badges' => ['Novo u ponudi'],
        ]));

        $this->assertStringNotContainsString('<div class="stamp"', $hook);
        $this->assertStringContainsString('<span class="badge">Novo u ponudi</span>', $hook);
    }

    public function test_a_deal_slide_set_opens_on_its_own_hook(): void
    {
        $this->assertSame(
            ['kinds/deal-hook-story', 'kinds/deal-story', 'kinds/cta-story'],
            app(\App\Rendering\TemplateRegistry::class)->slidesFor(ContentKind::Deal, 'story'),
        );
        // The card, not the hook, is still what a deal is rendered as when nothing else is asked.
        $this->assertSame('kinds/deal-portrait', app(\App\Rendering\TemplateRegistry::class)->defaultFor(ContentKind::Deal, 'portrait'));
    }

    private function html(string $key, ContentItem $item): string
    {
        return app(ImageRenderer::class)->html($item->brand, $key, app(TemplateData::class)->forItem($item, $item->brand));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $voice
     */
    private function deal(array $overrides = [], bool $image = true, array $voice = []): ContentItem
    {
        Http::fake([self::CROP => Http::response($this->png(640, 437), 200, ['Content-Type' => 'image/png'])]);

        $brand = Brand::factory()->create(['name' => 'Listo', 'site_url' => 'https://uselisto.com', 'voice' => $voice]);

        return ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(array_replace([
            'kind' => ContentKind::Deal,
            'title' => 'Brancin očišćeni svježi',
            'subtitle' => 'Konzum',
            'facts' => [],
            'badges' => [],
            'price' => ['current_cents' => 1319, 'old_cents' => 1899, 'discount_pct' => 30, 'currency' => 'EUR', 'unit_label' => null],
            'expires_at' => null,
            'images' => $image ? [['url' => self::CROP, 'role' => 'primary']] : [],
        ], $overrides));
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
