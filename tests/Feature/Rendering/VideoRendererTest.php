<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Rendering\VideoRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real ffmpeg. The point is not that a file appears but that it is the shape Reels and TikTok
 * accept: H.264 in yuv420p, vertical, with an audio track and the moov atom up front.
 */
#[Group('render')]
final class VideoRendererTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $probe = new Process(['ffmpeg', '-version']);
        $probe->run();

        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('ffmpeg nije dostupan.');
        }
    }

    public function test_it_stitches_slides_into_a_vertical_mp4(): void
    {
        Storage::fake('public');
        config()->set('hub.media_disk', 'public');

        $brand = Brand::factory()->create(['slug' => 'uselisto']);
        $slides = collect([$this->slide($brand), $this->slide($brand), $this->slide($brand)]);

        $video = app(VideoRenderer::class)->slideshow($brand, $slides, secondsPerSlide: 3.0, transitionSeconds: 0.6);

        Storage::disk('public')->assertExists($video->path);
        $this->assertSame('mp4', $video->format);
        $this->assertTrue($video->isVideo());
        $this->assertSame([1080, 1920], [$video->width, $video->height]);
        $this->assertSame($slides->first()->id, $video->poster_media_asset_id, 'prvi slajd je naslovnica');

        // 3 slides x 3s minus 2 transitions x 0.6s.
        $this->assertEqualsWithDelta(7.8, (float) $video->durationSeconds(), 0.05);

        $probe = $this->probe(Storage::disk('public')->path($video->path));
        $this->assertStringContainsString('codec_name=h264', $probe);
        $this->assertStringContainsString('pix_fmt=yuv420p', $probe);
        $this->assertStringContainsString('codec_type=audio', $probe, 'neki uploaderi odbijaju video bez zvučnog zapisa');
        $this->assertEqualsWithDelta(7.8, (float) $this->duration($probe), 0.2);
    }

    public function test_a_single_slide_still_clears_the_three_second_floor(): void
    {
        Storage::fake('public');
        config()->set('hub.media_disk', 'public');

        $brand = Brand::factory()->create();

        // Instagram and TikTok both reject anything shorter than three seconds.
        $video = app(VideoRenderer::class)->slideshow($brand, collect([$this->slide($brand)]), secondsPerSlide: 1.5);

        $this->assertGreaterThanOrEqual(3.0, (float) $video->durationSeconds());
    }

    public function test_it_refuses_to_render_nothing(): void
    {
        $this->expectException(RuntimeException::class);

        app(VideoRenderer::class)->slideshow(Brand::factory()->create(), collect());
    }

    private function slide(Brand $brand): MediaAsset
    {
        Storage::disk('public')->makeDirectory('slides');
        $path = Storage::disk('public')->path('slides/'.uniqid('slide-').'.jpg');

        // A real JPEG, not a stub: ffmpeg decodes the file it is given.
        $image = imagecreatetruecolor(1080, 1920);
        imagefill($image, 0, 0, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagejpeg($image, $path, 85);
        imagedestroy($image);

        return MediaAsset::factory()->for($brand)->create([
            'width' => 1080,
            'height' => 1920,
            'format' => 'jpg',
            'disk' => 'public',
            'path' => 'slides/'.basename($path),
            'bytes' => filesize($path) ?: null,
        ]);
    }

    private function probe(string $file): string
    {
        $process = new Process([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-show_entries', 'stream=codec_name,codec_type,width,height,pix_fmt',
            '-of', 'default=noprint_wrappers=1',
            $file,
        ]);
        $process->run();

        return $process->getOutput();
    }

    private function duration(string $probe): string
    {
        preg_match('/duration=([\d.]+)/', $probe, $matches);

        return $matches[1] ?? '0';
    }
}
