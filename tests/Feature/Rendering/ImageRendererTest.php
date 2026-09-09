<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Real Chromium render. Runs wherever Browsershot can find a browser (Sail image, production VPS);
 * skipped elsewhere so the suite stays green on machines without Chrome.
 */
#[Group('render')]
final class ImageRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_end_of_day_expiry_keeps_its_own_date(): void
    {
        $brand = Brand::factory()->create(['timezone' => 'Europe/Zagreb']);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->create([
            'expires_at' => '2026-09-15T23:59:59Z',
        ]);

        $data = app(TemplateData::class)->forItem($item, $brand);

        // Rendering 23:59:59Z in Europe/Zagreb lands at 01:59 on the 16th, which contradicted the
        // "vrijedi do" the source itself states.
        $this->assertSame('15.09.2026', $data['expires_at']);
    }

    public function test_renders_a_job_card_as_a_1080_square_jpeg(): void
    {
        $chrome = (string) config('hub.render.chrome_path');
        if ($chrome !== '' && ! is_file($chrome)) {
            $this->markTestSkipped("No Chromium at {$chrome}");
        }

        Storage::fake('public');
        Http::fake(['*' => Http::response('', 404)]); // source images unreachable → emoji placeholder

        $brand = Brand::factory()->create(['slug' => 'studentski-poslovi', 'name' => 'Studentski poslovi']);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'title' => 'Konobar/ica u hotelu na plaži — sezona 2026 čšžćđ',
            'subtitle' => 'Hotel Adriatic d.o.o.',
            'badges' => ['Sezonski posao', 'Smještaj', 'Obrok'],
            'facts' => [['label' => 'LOKACIJA', 'value' => 'SPLIT'], ['label' => 'SATNICA', 'value' => '7.00 - 8.00 €/H']],
        ]);

        $asset = app(ImageRenderer::class)->render($brand, 'kinds/job-square', app(TemplateData::class)->forItem($item, $brand));

        Storage::disk('public')->assertExists($asset->path);
        $this->assertSame([1080, 1080, 'jpg'], [$asset->width, $asset->height, $asset->format]);
        $this->assertStringStartsWith('media/studentski-poslovi/', $asset->path);
        $this->assertGreaterThan(10_000, $asset->bytes);

        [$w, $h, $type] = getimagesize(Storage::disk('public')->path($asset->path));
        $this->assertSame([1080, 1080, IMAGETYPE_JPEG], [$w, $h, $type]);
        $this->assertStringStartsWith('http', $asset->publicUrl());
    }
}
