<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\CreateDraft;
use App\Catalog\CatalogCopy;
use App\Catalog\CatalogDemo;
use App\Catalog\CatalogVideoPlan;
use App\Enums\Platform;
use App\Jobs\RenderCatalogVideoJob;
use App\Models\Brand;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Notifications\VoiceoverUnavailable;
use App\Publishing\FormatCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSpeech;
use Tests\Support\MakesCatalogItems;
use Tests\Support\MakesVideoFixtures;
use Tests\TestCase;

/**
 * The whole path with the real renderers — Chromium draws the frames of the scene, ffmpeg mixes and encodes — and only
 * the network faked: the leaflet's pictures and ElevenLabs.
 */
#[Group('render')]
final class RenderCatalogVideoJobTest extends TestCase
{
    use MakesCatalogItems;
    use MakesVideoFixtures;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $chrome = (string) config('hub.render.chrome_path');

        if ($chrome !== '' && ! is_file($chrome)) {
            $this->markTestSkipped("No Chromium at {$chrome}");
        }

        Storage::fake('public');
        Storage::fake('local');
        Sleep::fake();
        config()->set('hub.media_disk', 'public');
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('elevenlabs.disk', 'local');
        config()->set('hub.admin_emails', ['ops@example.test']);
    }

    public function test_a_video_comes_out_in_the_shape_every_uploader_takes(): void
    {
        FakeSpeech::fake($this->requests);
        $this->fakePictures();

        [$draft, $variant] = $this->draft();

        RenderCatalogVideoJob::dispatchSync($draft->id, [$variant->id], voiceover: true);

        $asset = $variant->refresh()->media()->firstOrFail();
        $file = $asset->absolutePath();

        $this->assertTrue($asset->isVideo());
        $this->assertSame('video/catalog-demo', $asset->template_key);
        $this->assertSame([1080, 1920], [$asset->width, $asset->height]);
        $this->assertNull(app(FormatCheck::class)->problem($variant), 'it is ready to publish');

        $video = $this->streams($file);
        $this->assertSame('h264', $video['v']['codec_name']);
        $this->assertSame('yuv420p', $video['v']['pix_fmt']);
        $this->assertSame('30/1', $video['v']['r_frame_rate']);
        $this->assertSame('aac', $video['a']['codec_name']);
        $this->assertSame('44100', $video['a']['sample_rate']);
        $this->assertTrue($this->hasFastStart($file), 'the moov atom is before the media, so a feed can start it at once');

        // The length is the plan's: what the picture waits for is what the voice takes.
        $this->assertEqualsWithDelta((float) $asset->durationSeconds(), (float) $video['v']['duration'], 0.1);
        $this->assertEqualsWithDelta((float) $asset->durationSeconds(), (float) $video['a']['duration'], 0.1);
        $this->assertGreaterThan(14.0, (float) $asset->durationSeconds());
        $this->assertLessThan(20.0, (float) $asset->durationSeconds());

        // Four sentences, spoken and kept with the video.
        $this->assertSame('ok', $asset->params['voiceover']['status']);
        $this->assertSame(CatalogCopy::voiceLines(CatalogDemo::fromItem($draft->contentItems->first()), cta: 'Preuzmi Listo'), array_column($this->requests, 'text'));
        $this->assertCount(4, $asset->params['voiceover']['clips']);
        $this->assertSame('comment', $asset->params['link_in']);
        $this->assertCount(3, $asset->params['taps']);
        $this->assertSame(1037, $asset->params['total_cents']);

        // The cover is the second after the first tap, as a picture.
        $poster = $asset->poster;
        $this->assertNotNull($poster);
        $this->assertSame('jpg', $poster->format);

        // TikTok takes its cover from a moment of the video: the second after the first tap, not the first frame.
        $this->assertEqualsWithDelta(($asset->params['timeline']['taps'][0]['at'] + 0.5) * 1000, (float) $variant->setting('cover_timestamp_ms'), 2.0);

        // What the mix is for: the loudness short video is mixed at, and the peak the networks take.
        [$lufs, $peak] = $this->loudness($file);
        $this->assertEqualsWithDelta(-14.0, $lufs, 0.8);
        $this->assertLessThanOrEqual(-1.5, $peak, 'true peak');

        // The work folder is gone: a render leaves no frames behind.
        $this->assertSame([], glob(storage_path('app/tmp/catalog-video-*')) ?: []);
    }

    public function test_the_sound_of_adding_is_in_the_finished_video_on_the_frame_of_each_tap(): void
    {
        $this->fakePictures();
        config()->set('elevenlabs.api_key', null);

        [$draft, $variant] = $this->draft(voice: false);

        RenderCatalogVideoJob::dispatchSync($draft->id, [$variant->id]);

        $asset = $variant->refresh()->media()->firstOrFail();
        $taps = array_column($asset->params['timeline']['taps'], 'at');

        $process = new Process(['ffmpeg', '-hide_banner', '-i', $asset->absolutePath(), '-map', '0:a', '-af', 'silencedetect=noise=-45dB:d=0.15', '-f', 'null', '-']);
        $process->run();
        preg_match_all('/silence_end: ([\d.]+)/', $process->getErrorOutput(), $matches);
        $onsets = array_values(array_filter(array_map('floatval', $matches[1]), fn (float $at): bool => $at < (float) $asset->durationSeconds() - 0.1));

        $this->assertCount(3, $onsets, json_encode($onsets));

        foreach ($taps as $i => $at) {
            // AAC delays everything by its priming (about 1024 samples), and the ear cannot tell; a frame is the limit.
            $this->assertEqualsWithDelta($at, $onsets[$i], 0.045, "tap {$i}");
        }
    }

    public function test_when_the_voice_cannot_be_made_the_video_still_comes_out_and_says_why(): void
    {
        Notification::fake();
        $this->fakePictures();
        Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'quota_exceeded', 'message' => 'You have 0 credits']], 401)]);

        [$draft, $variant] = $this->draft();

        RenderCatalogVideoJob::dispatchSync($draft->id, [$variant->id], voiceover: true);

        $asset = $variant->refresh()->media()->firstOrFail();
        $this->assertTrue($asset->isVideo(), 'a post never waits for its voice');
        $this->assertSame('failed', $asset->params['voiceover']['status']);
        $this->assertSame('quota_exceeded', $asset->params['voiceover']['code']);
        $this->assertNull($variant->renderError());
        $this->assertNull(app(FormatCheck::class)->problem($variant));
        $this->assertFalse($variant->aiGenerated(), 'without a synthetic voice there is nothing to label');
        $this->assertGreaterThan(13.0, (float) $asset->durationSeconds(), 'the same video, timed by the sentences');

        Notification::assertSentOnDemand(VoiceoverUnavailable::class, fn (VoiceoverUnavailable $notification): bool => $notification->errorCode === 'quota_exceeded');
    }

    public function test_a_leaflet_page_that_cannot_be_fetched_fails_the_render_and_says_so(): void
    {
        // Another address than the other tests: fetched pictures are kept for a week, so a known one would be found.
        Http::fake(['missing.test/*' => Http::response('', 404)]);

        [$draft, $variant] = $this->draft(voice: false, host: 'missing.test');
        $variant->markRendering();

        try {
            RenderCatalogVideoJob::dispatchSync($draft->id, [$variant->id]);
            $this->fail('Expected the render to fail');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('se ne može dohvatiti', $e->getMessage());
        }

        $variant->refresh();
        $this->assertStringContainsString('letka', (string) $variant->renderError());
        $this->assertSame(0, $variant->media()->count());
    }

    public function test_a_demo_that_does_not_hold_up_fails_before_any_work_is_done(): void
    {
        $feed = $this->catalogFeedItem();
        $feed['raw']['demo']['total_cents'] = 1;

        [$draft, $variant] = $this->draft(voice: false, feed: $feed);

        try {
            RenderCatalogVideoJob::dispatchSync($draft->id, [$variant->id]);
            $this->fail('Expected the render to fail');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('nije zbroj cijena', $e->getMessage());
        }

        $this->assertStringContainsString('raw.demo ne valja', (string) $variant->refresh()->renderError());
    }

    public function test_the_plan_the_video_was_made_from_is_the_plan_for_its_sentences(): void
    {
        FakeSpeech::fake($this->requests);
        $this->fakePictures();

        [$draft, $variant] = $this->draft();
        RenderCatalogVideoJob::dispatchSync($draft->id, [$variant->id], voiceover: true);

        $asset = $variant->refresh()->media()->firstOrFail();
        $demo = CatalogDemo::fromItem($draft->contentItems->first());
        $plan = CatalogVideoPlan::build($demo, CatalogCopy::voiceLines($demo), array_map(fn (array $clip): float => $clip['seconds'], $asset->params['voiceover']['clips']));

        $this->assertEqualsWithDelta($plan['duration'], $asset->duration_ms / 1000, 0.01);
        $this->assertEquals(array_column($plan['taps'], 'at'), array_column($asset->params['timeline']['taps'], 'at'));
    }

    /**
     * @param  array<string, mixed>|null  $feed
     * @return array{0: PostDraft, 1: PostVariant}
     */
    private function draft(bool $voice = true, ?array $feed = null, string $host = 'img.test'): array
    {
        $brand = Brand::factory()->create([
            'slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com',
            'voice' => ['cta' => 'Preuzmi Listo'],
            'voiceover' => $voice ? ['enabled' => true, 'voice_id' => 'voice-1', 'accents' => 'off'] : null,
        ]);

        // The leaflet's pictures are fetched from here, not from Listo.
        $feed = json_decode(str_replace('https://api.uselisto.com', "https://{$host}", json_encode($feed ?? $this->catalogFeedItem(), JSON_UNESCAPED_SLASHES)), true);
        $item = $this->catalogItem($brand, $feed);
        $draft = app(CreateDraft::class)->execute($item, $this->catalogAccounts($brand, [Platform::TikTok]), render: false);

        return [$draft, $draft->variants->first()];
    }

    private function fakePictures(): void
    {
        $image = imagecreatetruecolor(400, 550);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 90, 60));
        imagefilledrectangle($image, 40, 60, 360, 300, imagecolorallocate($image, 250, 220, 80));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        Http::fake(['img.test/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    }

    /**
     * @return array{v: array<string, string>, a: array<string, string>}
     */
    private function streams(string $file): array
    {
        $process = new Process(['ffprobe', '-v', 'error', '-show_streams', '-of', 'json', $file]);
        $process->mustRun();
        $streams = json_decode($process->getOutput(), true)['streams'];

        return [
            'v' => collect($streams)->firstWhere('codec_type', 'video'),
            'a' => collect($streams)->firstWhere('codec_type', 'audio'),
        ];
    }

    private function hasFastStart(string $file): bool
    {
        $head = (string) file_get_contents($file, false, null, 0, 65536);

        return ($moov = mb_strpos($head, 'moov', 0, '8bit')) !== false && ($mdat = mb_strpos($head, 'mdat', 0, '8bit')) !== false && $moov < $mdat;
    }

    /**
     * @return array{0: float, 1: float} Integrated loudness (LUFS) and true peak (dBTP).
     */
    private function loudness(string $file): array
    {
        $process = new Process(['ffmpeg', '-hide_banner', '-nostats', '-i', $file, '-af', 'ebur128=peak=true', '-f', 'null', '-']);
        $process->run();
        $output = $process->getErrorOutput();
        $summary = mb_substr($output, (int) mb_strrpos($output, 'Summary:'));
        preg_match('/I:\s+(-?[\d.]+) LUFS/', $summary, $integrated);
        preg_match('/Peak:\s+(-?[\d.]+) dBFS/', $summary, $peak);

        return [(float) ($integrated[1] ?? 0), (float) ($peak[1] ?? 0)];
    }
}
