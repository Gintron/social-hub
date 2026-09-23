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

    public function test_the_brands_end_card_stays_long_enough_to_be_read(): void
    {
        Storage::fake('public');
        config()->set('hub.media_disk', 'public');

        $brand = Brand::factory()->create(['slug' => 'uselisto']);
        $end = $this->slide($brand);
        $end->update(['template_key' => 'kinds/cta-story']);
        $slides = collect([$this->slide($brand), $this->slide($brand), $end]);

        $video = app(VideoRenderer::class)->slideshow($brand, $slides, secondsPerSlide: 2.0, transitionSeconds: 0.35);

        // Hook and card keep two seconds each; the end card gets END_CARD_SECONDS. 2 + 2 + 3.5 − 2 × 0.35.
        $this->assertEqualsWithDelta(6.8, (float) $video->durationSeconds(), 0.05);
        $this->assertSame(VideoRenderer::END_CARD_SECONDS, $video->params['last_slide_seconds']);
        $this->assertEqualsWithDelta(6.8, (float) $this->duration($this->probe(Storage::disk('public')->path($video->path))), 0.2);

        // A last slide that is someone's offer, not the brand's card, keeps the common rhythm.
        $plain = app(VideoRenderer::class)->slideshow($brand, collect([$this->slide($brand), $this->slide($brand)]), secondsPerSlide: 2.0, transitionSeconds: 0.35);
        $this->assertEqualsWithDelta(3.65, (float) $plain->durationSeconds(), 0.05);
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

    public function test_the_brand_track_plays_under_the_video_and_the_video_decides_the_length(): void
    {
        Storage::fake('public');
        config()->set('hub.media_disk', 'public');

        $brand = Brand::factory()->create();
        $slides = collect([$this->slide($brand), $this->slide($brand), $this->slide($brand)]);

        // Two seconds of tone under a 7.8-second video: it has to loop, not stop or stretch the video.
        $track = $this->track(seconds: 2);

        $video = app(VideoRenderer::class)->slideshow($brand, $slides, secondsPerSlide: 3.0, transitionSeconds: 0.6, audioPath: $track);

        $file = Storage::disk('public')->path($video->path);
        $probe = $this->probe($file);

        $this->assertStringContainsString('codec_name=aac', $probe);
        $this->assertEqualsWithDelta(7.8, (float) $this->duration($probe), 0.2);
        $this->assertGreaterThan(-40.0, $this->meanVolume($file), 'pjesma se mora čuti');
        $this->assertSame(basename($track), $video->params['audio']);
    }

    public function test_without_a_track_the_audio_stream_is_silent(): void
    {
        Storage::fake('public');
        config()->set('hub.media_disk', 'public');

        $brand = Brand::factory()->create();

        $video = app(VideoRenderer::class)->slideshow($brand, collect([$this->slide($brand)]), secondsPerSlide: 3.0);
        $file = Storage::disk('public')->path($video->path);

        $this->assertStringContainsString('codec_type=audio', $this->probe($file));
        $this->assertLessThan(-80.0, $this->meanVolume($file));
        $this->assertNull($video->params['audio']);
    }

    public function test_slides_drift_unless_motion_is_off(): void
    {
        Storage::fake('public');
        config()->set('hub.media_disk', 'public');

        $brand = Brand::factory()->create();
        $slide = $this->slide($brand);

        $moving = app(VideoRenderer::class)->slideshow($brand, collect([$slide]), secondsPerSlide: 3.0);
        $still = app(VideoRenderer::class)->slideshow($brand, collect([$slide]), secondsPerSlide: 3.0, motion: false);

        $this->assertTrue($moving->params['motion']);
        $this->assertGreaterThan(5.0, $this->frameDifference(Storage::disk('public')->path($moving->path), 0.2, 2.5));
        $this->assertLessThan(2.0, $this->frameDifference(Storage::disk('public')->path($still->path), 0.2, 2.5));
    }

    public function test_a_missing_track_is_refused_before_ffmpeg_runs(): void
    {
        $this->expectException(RuntimeException::class);

        $brand = Brand::factory()->create();

        app(VideoRenderer::class)->slideshow($brand, collect([MediaAsset::factory()->for($brand)->create()]), audioPath: '/nope/track.mp3');
    }

    private function slide(Brand $brand): MediaAsset
    {
        Storage::disk('public')->makeDirectory('slides');
        $path = Storage::disk('public')->path('slides/'.uniqid('slide-').'.jpg');

        // A real JPEG, not a stub: ffmpeg decodes the file it is given. Stripes, because zooming a
        // flat colour changes no pixels and the motion test would prove nothing.
        $image = imagecreatetruecolor(1080, 1920);
        imagefill($image, 0, 0, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $stripe = imagecolorallocate($image, 0, 0, 0);

        for ($x = 0; $x < 1080; $x += 80) {
            imagefilledrectangle($image, $x, 0, $x + 39, 1919, $stripe);
        }

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

    private function track(int $seconds): string
    {
        Storage::disk('public')->makeDirectory('brands/audio');
        $path = Storage::disk('public')->path('brands/audio/'.uniqid('tone-').'.m4a');

        $process = new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}", '-c:a', 'aac', $path]);
        $process->mustRun();

        return $path;
    }

    private function meanVolume(string $file): float
    {
        $process = new Process(['ffmpeg', '-hide_banner', '-i', $file, '-map', '0:a', '-af', 'volumedetect', '-f', 'null', '-']);
        $process->run();

        preg_match('/mean_volume:\s*(-?[\d.]+|-inf) dB/', $process->getErrorOutput(), $matches);

        return ($matches[1] ?? '-inf') === '-inf' ? -INF : (float) $matches[1];
    }

    /**
     * Mean absolute difference (0–255) between two frames, compared small and in grey so that
     * encoder noise on a still frame stays near zero.
     */
    private function frameDifference(string $file, float $first, float $second): float
    {
        $frame = function (float $at) use ($file): string {
            $process = new Process([
                'ffmpeg', '-v', 'error', '-ss', (string) $at, '-i', $file,
                '-frames:v', '1', '-vf', 'scale=270:480,format=gray', '-f', 'rawvideo', '-',
            ]);
            $process->mustRun();

            return $process->getOutput();
        };

        $a = $frame($first);
        $b = $frame($second);
        $this->assertSame(270 * 480, mb_strlen($a, '8bit'));

        $sum = 0;
        for ($i = 0, $length = mb_strlen($a, '8bit'); $i < $length; $i++) {
            $sum += abs(ord($a[$i]) - ord($b[$i]));
        }

        return $sum / $length;
    }
}
