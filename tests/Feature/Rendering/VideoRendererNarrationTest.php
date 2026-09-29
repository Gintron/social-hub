<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Models\Brand;
use App\Rendering\VideoRenderer;
use App\Voiceover\Narration;
use App\Voiceover\NarrationClip;
use App\Voiceover\Script;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\MakesVideoFixtures;
use Tests\TestCase;

/**
 * A voice over the slides, measured in the file that comes out: each line inside its own slide, the
 * music pressed down while it speaks, the video exactly as long as it should be. Real ffmpeg, with
 * tones for voices — a 440 Hz tone is the narrator, a 220 Hz tone the brand's music, so the two can be
 * told apart by filtering.
 */
#[Group('render')]
final class VideoRendererNarrationTest extends TestCase
{
    use MakesVideoFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $probe = new Process(['ffmpeg', '-version']);
        $probe->run();

        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('ffmpeg nije dostupan.');
        }

        Storage::fake('public');
        Storage::fake('local');
        config()->set('hub.media_disk', 'public');
    }

    public function test_a_slide_stays_up_for_as_long_as_its_line_takes_and_never_less_than_before(): void
    {
        $brand = Brand::factory()->create();

        $video = app(VideoRenderer::class)->slideshow(
            $brand,
            collect([$this->slide($brand), $this->slide($brand), $this->slide($brand)]),
            secondsPerSlide: 2.0,
            transitionSeconds: 0.0,
            motion: false,
            narration: $this->narration([$this->clip(3.0), null, $this->clip(1.0)]),
        );

        // 0.2 before the line and 0.4 after it: 3.6 for a 3-second line; the short line and the silent slide
        // keep the two seconds they had.
        $this->assertEqualsWithDelta([3.6, 2.0, 2.0], $video->params['slide_seconds'], 0.001);
        $this->assertEqualsWithDelta(7.6, (float) $video->durationSeconds(), 0.05);
        $this->assertEqualsWithDelta(7.6, $this->streamSeconds($video->absolutePath(), 'video'), 0.15);
        $this->assertEqualsWithDelta(7.6, $this->streamSeconds($video->absolutePath(), 'audio'), 0.15, 'the sound never ends before the picture does');
    }

    public function test_crossfades_are_counted_so_a_line_ends_before_the_next_slide_starts_to_arrive(): void
    {
        $brand = Brand::factory()->create();

        $video = app(VideoRenderer::class)->slideshow(
            $brand,
            collect([$this->slide($brand), $this->slide($brand), $this->slide($brand)]),
            secondsPerSlide: 1.5,
            transitionSeconds: 0.5,
            motion: false,
            narration: $this->narration([$this->clip(2.0), $this->clip(2.0), $this->clip(2.0)]),
        );

        // First slide: lead + line + tail + one fade out. Middle: fades on both sides. Last: one fade in.
        $this->assertEqualsWithDelta([3.1, 3.6, 3.1], $video->params['slide_seconds'], 0.001);
        // Each line starts after its slide has fully arrived: 0.2, then 3.1 − 0.5 + 0.5 + 0.2 …
        $this->assertEqualsWithDelta([0.2, 3.3, 6.4], $video->params['voiceover']['starts'], 0.01);
        $this->assertEqualsWithDelta(3.1 + 3.6 + 3.1 - 2 * 0.5, (float) $video->durationSeconds(), 0.05);
    }

    public function test_each_line_is_heard_inside_its_own_slide_and_nowhere_else(): void
    {
        $brand = Brand::factory()->create();

        $video = app(VideoRenderer::class)->slideshow(
            $brand,
            collect([$this->slide($brand), $this->slide($brand), $this->slide($brand)]),
            secondsPerSlide: 2.0,
            transitionSeconds: 0.0,
            motion: false,
            narration: $this->narration([$this->clip(3.0), null, $this->clip(1.5)]),
        );
        $file = $video->absolutePath();

        // Line 1 from 0.2 to 3.2; the silent slide 3.6–5.6; line 3 from 5.8 to 7.3.
        $this->assertGreaterThan(-25.0, $this->level($file, 0.4, 3.0), 'the first line is heard');
        $this->assertLessThan(-70.0, $this->level($file, 3.35, 5.55), 'the slide with no line is silent (no music here)');
        $this->assertGreaterThan(-25.0, $this->level($file, 5.95, 7.15), 'the last line is heard');
        $this->assertLessThan(-70.0, $this->level($file, 0.0, 0.15), 'a beat of quiet before the first word');
    }

    public function test_the_music_is_pressed_down_while_the_voice_speaks_and_comes_back_after(): void
    {
        $brand = Brand::factory()->create();
        $music = $this->toneTrack(4, 200);
        $render = fn (array $clips) => app(VideoRenderer::class)->slideshow(
            $brand,
            collect([$this->slide($brand), $this->slide($brand), $this->slide($brand)]),
            secondsPerSlide: 3.0,
            transitionSeconds: 0.0,
            audioPath: $music,
            motion: false,
            narration: $this->narration($clips),
        );

        // The same music, the same length and the same line — in the middle slide in one video and in the last
        // in the other. What differs between the two in the same window is the voice, nothing else.
        // The music is a 200 Hz tone and the voice a 1500 Hz one, far enough apart to be measured apart.
        $speaking = $render([null, $this->clip(2.0, 1500), null]);
        $waiting = $render([null, null, $this->clip(2.0, 1500)]);

        // The line sits at 3.2–5.2 in the first (slide two, lead 0.2).
        $under = $this->level($speaking->absolutePath(), 3.6, 5.0, 200);
        $alone = $this->level($waiting->absolutePath(), 3.6, 5.0, 200);
        $voice = $this->level($speaking->absolutePath(), 3.6, 5.0, 1500);

        $this->assertGreaterThan(-60.0, $alone, 'the music plays');
        $this->assertLessThan($alone - 6.0, $under, 'it is pressed down while the voice speaks');
        $this->assertGreaterThan($under + 10.0, $voice, 'the voice sits well above what is left of it');

        // And it returns once the voice has stopped (the compressor releases, it does not stay down).
        $after = $this->level($speaking->absolutePath(), 6.4, 8.8, 200);
        $this->assertGreaterThan($under + 4.0, $after);
    }

    public function test_the_music_plays_lower_under_a_voice_than_it_does_alone(): void
    {
        $brand = Brand::factory()->create();
        $slides = fn () => collect([$this->slide($brand), $this->slide($brand)]);
        $music = $this->toneTrack(4, 220);

        $alone = app(VideoRenderer::class)->slideshow($brand, $slides(), secondsPerSlide: 3.0, transitionSeconds: 0.0, audioPath: $music, motion: false);
        $under = app(VideoRenderer::class)->slideshow($brand, $slides(), secondsPerSlide: 3.0, transitionSeconds: 0.0, audioPath: $music, motion: false, narration: $this->narration([null, $this->clip(1.0, 1500)]));

        // Same slides, same track: with a narration the music is the bed, not the whole soundtrack.
        $this->assertLessThan(
            $this->level($alone->absolutePath(), 0.5, 2.5, 220) - 8.0,
            $this->level($under->absolutePath(), 0.5, 2.5, 220),
            'the bed is set below where the music plays on its own',
        );
    }

    public function test_the_brands_music_level_moves_the_bed(): void
    {
        $brand = Brand::factory()->create();
        $music = $this->toneTrack(4, 220);
        $render = fn (float $gain) => app(VideoRenderer::class)->slideshow(
            $brand,
            collect([$this->slide($brand), $this->slide($brand)]),
            secondsPerSlide: 3.0,
            transitionSeconds: 0.0,
            audioPath: $music,
            motion: false,
            narration: $this->narration([null, $this->clip(1.0, 1500)], musicGainDb: $gain),
        );

        $quiet = $this->level($render(-20.0)->absolutePath(), 0.5, 2.5, 220);
        $loud = $this->level($render(-8.0)->absolutePath(), 0.5, 2.5, 220);

        $this->assertEqualsWithDelta(12.0, $loud - $quiet, 2.0);
    }

    public function test_a_clip_is_brought_to_its_own_level_before_it_is_placed(): void
    {
        $brand = Brand::factory()->create();
        $video = app(VideoRenderer::class)->slideshow(
            $brand,
            collect([$this->slide($brand), $this->slide($brand)]),
            secondsPerSlide: 2.0,
            transitionSeconds: 0.0,
            motion: false,
            // The same tone twice, the second told to come down 12 dB: what the loudness measurement asks for.
            narration: $this->narration([$this->clip(1.2), $this->clip(1.2, gainDb: -12.0)]),
        );

        $first = $this->level($video->absolutePath(), 0.4, 1.3);
        $second = $this->level($video->absolutePath(), 2.8, 3.6);

        $this->assertEqualsWithDelta(12.0, $first - $second, 1.5);
    }

    public function test_a_video_with_a_voice_and_no_music_is_the_voice_over_a_silent_bed(): void
    {
        $brand = Brand::factory()->create();

        $video = app(VideoRenderer::class)->slideshow(
            $brand,
            collect([$this->slide($brand), $this->slide($brand)]),
            secondsPerSlide: 2.0,
            transitionSeconds: 0.0,
            motion: false,
            narration: $this->narration([$this->clip(1.5), null]),
        );

        $probe = $this->probe($video->absolutePath());
        $this->assertStringContainsString('codec_name=aac', $probe);
        $this->assertStringContainsString('codec_name=h264', $probe);
        $this->assertGreaterThan(-30.0, $this->level($video->absolutePath(), 0.3, 1.6));
        $this->assertNull($video->params['audio']);
        $this->assertSame('ok', $video->params['voiceover']['status']);
    }

    public function test_a_narration_with_nothing_in_it_leaves_the_video_as_it_was(): void
    {
        $brand = Brand::factory()->create();

        $video = app(VideoRenderer::class)->slideshow(
            $brand,
            collect([$this->slide($brand), $this->slide($brand)]),
            secondsPerSlide: 2.0,
            transitionSeconds: 0.0,
            motion: false,
            narration: $this->narration([null, null]),
        );

        $this->assertNull($video->params['voiceover']);
        $this->assertEqualsWithDelta(4.0, (float) $video->durationSeconds(), 0.05);
    }

    public function test_it_keeps_the_formats_the_platforms_accept(): void
    {
        $brand = Brand::factory()->create();

        $video = app(VideoRenderer::class)->slideshow(
            $brand,
            collect([$this->slide($brand), $this->slide($brand)]),
            audioPath: $this->toneTrack(4),
            narration: $this->narration([$this->clip(2.0), $this->clip(2.0)]),
        );

        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_name,pix_fmt,width,height,channels,sample_rate', '-of', 'default=noprint_wrappers=1', $video->absolutePath()]);
        $process->run();
        $probe = $process->getOutput();

        $this->assertStringContainsString('codec_name=h264', $probe);
        $this->assertStringContainsString('pix_fmt=yuv420p', $probe);
        $this->assertStringContainsString('codec_name=aac', $probe);
        $this->assertStringContainsString('sample_rate=44100', $probe);
        $this->assertStringContainsString('channels=2', $probe);
        $this->assertSame([1080, 1920], [$video->width, $video->height]);
    }

    public function test_the_narration_has_to_match_the_slides(): void
    {
        $brand = Brand::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('jedan redak po slajdu');

        app(VideoRenderer::class)->slideshow($brand, collect([$this->slide($brand), $this->slide($brand)]), narration: $this->narration([$this->clip(1.0)]));
    }

    public function test_a_clip_that_has_gone_from_disk_is_refused_before_ffmpeg_runs(): void
    {
        $brand = Brand::factory()->create();
        $clip = new NarrationClip('/nope/clip.mp3', 1.0, 0.0, 'x', 0, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ne postoji');

        app(VideoRenderer::class)->slideshow($brand, collect([$this->slide($brand)]), narration: $this->narration([$clip]));
    }

    /**
     * @param  list<NarrationClip|null>  $clips
     */
    private function narration(array $clips, float $musicGainDb = -14.0): Narration
    {
        return new Narration($clips, new Script([]), $musicGainDb, 'voice-1', 'eleven_multilingual_v2');
    }
}
