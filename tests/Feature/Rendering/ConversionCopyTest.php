<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Drafting\CaptionBuilder;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ConversionCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_conversion_campaign_asks_for_one_first_action_on_the_slide_and_in_every_social_caption(): void
    {
        Http::fake();
        $brand = Brand::factory()->create(['voice' => [
            'cta' => 'Preuzmi Listo',
            'activation' => 'Dodaj prvi proizvod s letka.',
        ]]);
        $item = ContentItem::factory()->for($brand)->for(Source::factory()->for($brand))->create([
            'kind' => ContentKind::Deal, 'images' => [],
        ]);
        $html = app(ImageRenderer::class)->html($brand, 'kinds/cta-story', app(TemplateData::class)->forItem($item, $brand));
        $this->assertStringContainsString('Dodaj prvi proizvod s letka.', $html);
        $this->assertStringNotContainsString('Spremi objavu', $html);

        foreach ([Platform::TikTok, Platform::InstagramBusiness] as $platform) {
            foreach ([app(CaptionBuilder::class)->for($platform, $item, $brand), app(CaptionBuilder::class)->digest($platform, collect([$item]), $brand, 'Prije kupnje')] as $caption) {
                $this->assertStringContainsString('Preuzmi Listo', $caption);
                $this->assertStringContainsString('Dodaj prvi proizvod s letka.', $caption);
                $this->assertStringNotContainsString('Spremi popis', $caption);
                $this->assertStringNotContainsString('Pošalji prijatelju', $caption);
            }
        }
    }

    public function test_storyboard_validation_does_not_create_drafts_or_render_partial_campaigns(): void
    {
        $brand = Brand::factory()->create();
        $manifest = tempnam(sys_get_temp_dir(), 'storyboard-test-');
        file_put_contents($manifest, json_encode(['slides' => [
            ['template' => 'kinds/feature-story', 'seconds' => 2.5, 'data' => ['title' => 'Prvi korak']],
            ['template' => 'missing', 'seconds' => 3.5, 'data' => []],
        ]]));
        try {
            $this->artisan('hub:render-storyboard', ['manifest' => $manifest, '--brand' => $brand->slug, '--output' => sys_get_temp_dir()])->assertFailed();
            $this->assertDatabaseCount('media_assets', 0);
            $this->assertDatabaseCount('post_drafts', 0);
        } finally {
            unlink($manifest);
        }
    }
}
