<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Models\Brand;
use App\Models\Voiceover;
use App\Voiceover\Synthesizer;
use App\Voiceover\VoiceoverException;
use App\Voiceover\VoiceoverSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSpeech;
use Tests\TestCase;

/**
 * A clip is paid for once and found again by what makes it sound the way it does.
 */
final class SynthesizerTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('elevenlabs.disk', 'local');

        FakeSpeech::fake($this->requests);
        $this->brand = Brand::factory()->create(['slug' => 'uselisto']);
    }

    public function test_a_clip_is_spoken_stored_and_recorded(): void
    {
        $clip = app(Synthesizer::class)->clip('Preuzmi Listo i dodaj prvi proizvod s letka.', $this->settings(), $this->brand);

        $this->assertSame('voice-1', $clip->voice_id);
        $this->assertSame('eleven_multilingual_v2', $clip->model);
        $this->assertSame('Preuzmi Listo i dodaj prvi proizvod s letka.', $clip->text);
        $this->assertSame($this->brand->id, $clip->brand_id);
        $this->assertSame(44, $clip->characters, 'characters is the length of what was said');
        $this->assertSame(FakeSpeech::cost('Preuzmi Listo i dodaj prvi proizvod s letka.'), $clip->cost);
        $this->assertNotSame($clip->characters, $clip->cost, 'the billed figure is kept apart from the length');
        $this->assertStringStartsWith('voiceovers/uselisto/', $clip->path);
        Storage::disk('local')->assertExists($clip->path);
        $this->assertSame(Storage::disk('local')->size($clip->path), $clip->bytes);
        $this->assertEqualsWithDelta(3.1, $clip->durationSeconds(), 0.15, 'the length is read from the file, not guessed');
        $this->assertNotNull($clip->loudness_lufs, 'the level is measured so a render can match clips to each other');
        $this->assertLessThan(0.0, $clip->true_peak_db);
        $this->assertStringStartsWith('req-', (string) $clip->request_id);
        $this->assertSame(0.55, $clip->settings['stability']);
        $this->assertCount(1, $this->requests);
    }

    public function test_a_clip_the_api_sent_no_cost_for_is_recorded_with_its_length_and_no_cost(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.elevenlabs.io/*' => Http::response(FakeSpeech::mp3(), 200, ['request-id' => 'req-1'])]);

        $clip = app(Synthesizer::class)->clip('Preuzmi Listo.', $this->settings(), $this->brand);

        $this->assertSame(14, $clip->characters);
        $this->assertNull($clip->cost);
    }

    public function test_the_same_words_in_the_same_voice_are_paid_for_once(): void
    {
        $first = app(Synthesizer::class)->clip('Preuzmi Listo.', $this->settings(), $this->brand);
        $second = app(Synthesizer::class)->clip('Preuzmi Listo.', $this->settings(), $this->brand);

        $this->assertTrue($first->is($second));
        $this->assertCount(1, $this->requests);
        $this->assertSame(1, Voiceover::query()->count());
    }

    public function test_another_brand_reuses_a_clip_of_the_same_words_and_voice(): void
    {
        app(Synthesizer::class)->clip('Preuzmi Listo.', $this->settings(), $this->brand);
        app(Synthesizer::class)->clip('Preuzmi Listo.', $this->settings(), Brand::factory()->create());

        $this->assertCount(1, $this->requests);
    }

    public function test_a_different_voice_model_or_setting_is_a_different_clip(): void
    {
        $synthesizer = app(Synthesizer::class);
        $synthesizer->clip('Preuzmi Listo.', $this->settings(), $this->brand);
        $synthesizer->clip('Preuzmi Listo.', $this->settings(['voice_id' => 'voice-2']), $this->brand);
        $synthesizer->clip('Preuzmi Listo.', $this->settings(['model' => 'eleven_v4']), $this->brand);
        $synthesizer->clip('Preuzmi Listo.', $this->settings(['speed' => 1.1]), $this->brand);
        $synthesizer->clip('Preuzmi Listo!', $this->settings(), $this->brand);

        $this->assertCount(5, $this->requests);
        $this->assertSame(5, Voiceover::query()->count());
    }

    public function test_a_clip_whose_file_is_gone_is_spoken_again_under_the_same_record(): void
    {
        $first = app(Synthesizer::class)->clip('Preuzmi Listo.', $this->settings(), $this->brand);
        Storage::disk('local')->delete($first->path);

        $second = app(Synthesizer::class)->clip('Preuzmi Listo.', $this->settings(), $this->brand);

        $this->assertTrue($first->is($second));
        Storage::disk('local')->assertExists($second->path);
        $this->assertCount(2, $this->requests);
        $this->assertSame(1, Voiceover::query()->count());
    }

    public function test_audio_that_cannot_be_read_leaves_no_file_and_no_record(): void
    {
        // Stubs match in the order they were added, so start from a clean client.
        Http::swap(new Factory);
        Http::fake(['api.elevenlabs.io/*' => Http::response(str_repeat('not audio at all. ', 60), 200)]);

        try {
            app(Synthesizer::class)->clip('Preuzmi Listo.', $this->settings(), $this->brand);
            $this->fail('A clip ffprobe cannot read must not be kept.');
        } catch (VoiceoverException $e) {
            $this->assertSame('probe_failed', $e->errorCode);
        }

        $this->assertSame(0, Voiceover::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_line_another_process_is_speaking_is_waited_for_and_then_found(): void
    {
        $settings = $this->settings();
        $hash = Synthesizer::hash('Preuzmi Listo.', $settings);

        // Another worker holds the lock and, a moment later, has recorded the clip.
        $lock = Cache::lock("voiceover.speak.{$hash}", 30);
        $this->assertTrue($lock->get());
        config()->set('elevenlabs.lock_wait_seconds', 1);

        try {
            app(Synthesizer::class)->clip('Preuzmi Listo.', $settings, $this->brand);
            $this->fail('A line that is being spoken elsewhere must not be spoken twice.');
        } catch (VoiceoverException $e) {
            $this->assertSame('unavailable', $e->errorCode);
            $this->assertTrue($e->retryable);
        }

        $this->assertSame([], $this->requests, 'nothing was paid for while the line was held');

        // Once the other worker has finished, the same call is served from what it recorded.
        $recorded = Voiceover::factory()->create(['hash' => $hash, 'path' => 'voiceovers/uselisto/'.$hash.'.mp3']);
        Storage::disk('local')->put($recorded->path, 'audio');
        $lock->release();

        $this->assertTrue($recorded->is(app(Synthesizer::class)->clip('Preuzmi Listo.', $settings, $this->brand)));
        $this->assertSame([], $this->requests);
    }

    public function test_a_brand_without_a_voice_cannot_speak(): void
    {
        $this->expectException(VoiceoverException::class);

        app(Synthesizer::class)->clip('Preuzmi Listo.', VoiceoverSettings::fromArray([]), $this->brand);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function settings(array $overrides = []): VoiceoverSettings
    {
        return VoiceoverSettings::fromArray([
            'enabled' => true,
            'voice_id' => 'voice-1',
            ...$overrides,
        ]);
    }
}
