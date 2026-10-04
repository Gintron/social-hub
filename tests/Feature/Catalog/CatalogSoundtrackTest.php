<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Catalog\CatalogCopy;
use App\Catalog\CatalogDemo;
use App\Catalog\CatalogVideoPlan;
use App\Rendering\CatalogVideo\CatalogVideoRenderer;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\MakesVideoFixtures;
use Tests\TestCase;

/**
 * "The reaction must fall exactly on the sound": the sound the app makes when a product is added starts on the frame
 * of each tap, whatever else is in the mix, and the mix is as long as the picture.
 */
final class CatalogSoundtrackTest extends TestCase
{
    use MakesVideoFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_the_added_sound_starts_on_the_frame_of_every_tap(): void
    {
        $plan = $this->plan(null);
        $file = $this->mix($plan, voices: false);

        $onsets = $this->onsets($file);

        $this->assertCount(3, $onsets, 'one sound per tap: '.json_encode($onsets));

        foreach ($plan['taps'] as $i => $tap) {
            $this->assertEqualsWithDelta($tap['at'], $onsets[$i], 1 / CatalogVideoPlan::FPS, "tap {$i}: sound at {$onsets[$i]}, frame at {$tap['at']}");
        }
    }

    public function test_voice_music_and_taps_make_one_track_as_long_as_the_video(): void
    {
        $plan = $this->plan([2.69, 4.05, 1.23, 2.69]);
        $file = $this->mix($plan, voices: true, music: true);

        $this->assertEqualsWithDelta($plan['duration'], $this->streamSeconds($file, 'audio'), 0.05);

        // Each sentence is heard where the plan puts it, and not before (its own pitch; 220 Hz is the music).
        foreach ($plan['voice'] as $i => $voice) {
            $band = 440 + 120 * $i;
            $during = $this->level($file, $voice['at'] + 0.3, $voice['at'] + $voice['seconds'] - 0.2, $band);
            $before = $this->level($file, max(0.0, $voice['at'] - 0.6), max(0.1, $voice['at'] - 0.2), $band);

            $this->assertGreaterThan($before + 20, $during, "sentence {$i} is heard from {$voice['at']} s");
        }
    }

    public function test_the_music_is_pressed_down_while_the_voice_speaks_and_comes_back(): void
    {
        $plan = $this->plan([2.69, 4.05, 1.23, 2.69]);
        $file = $this->mix($plan, voices: true, music: true);
        $voice = $plan['voice'][1];

        $speaking = $this->level($file, $voice['at'] + 1.0, $voice['at'] + $voice['seconds'] - 0.3, 220);
        // After the second sentence and before the third nobody speaks: that is the bed on its own.
        $end = $voice['at'] + $voice['seconds'];
        $alone = $this->level($file, $end + 1.3, $end + 1.6, 220);

        $this->assertLessThan($alone - 3.0, $speaking, 'the bed gives way to the voice');
    }

    public function test_without_music_or_a_voice_the_bed_is_silence_and_the_taps_are_still_there(): void
    {
        $plan = $this->plan(null);
        $file = $this->mix($plan, voices: false);

        $this->assertLessThan(-60.0, $this->level($file, 0.2, $plan['taps'][0]['at'] - 0.2), 'nothing before the first tap');
        $this->assertGreaterThan(-40.0, $this->level($file, $plan['taps'][0]['at'], $plan['taps'][0]['at'] + 0.3));
    }

    /**
     * @param  list<float>|null  $spoken
     * @return array<string, mixed>
     */
    private function plan(?array $spoken): array
    {
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/catalog-feed-konzum-2026-10-07.json')), true, flags: JSON_THROW_ON_ERROR);
        $demo = CatalogDemo::fromArray($payload['items'][0]['raw']['demo']);

        return CatalogVideoPlan::build($demo, CatalogCopy::voiceLines($demo), $spoken);
    }

    /**
     * The soundtrack as the renderer builds it, run through ffmpeg, as a WAV.
     *
     * @param  array<string, mixed>  $plan
     */
    private function mix(array $plan, bool $voices, bool $music = false): string
    {
        $arguments = ['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'];
        $index = 0;
        $inputs = ['music' => null, 'sound' => 0, 'voices' => []];

        if ($music) {
            $arguments = [...$arguments, '-stream_loop', '-1', '-i', $this->toneTrack(8, 220)];
            $inputs['music'] = $index++;
        }

        $arguments = [...$arguments, '-i', (string) config('catalog_video.add_sound')];
        $inputs['sound'] = $index++;

        $clips = [];

        if ($voices) {
            foreach ($plan['voice'] as $i => $voice) {
                // A pitch of its own per sentence, so each can be told apart from the previous one in the mix.
                $clip = $this->clip($voice['seconds'], 440 + 120 * $i);
                $clips[$i] = $clip;
                $arguments = [...$arguments, '-i', $clip->path];
                $inputs['voices'][$i] = $index++;
            }
        }

        $graph = app(CatalogVideoRenderer::class)->soundtrackGraph($plan, $clips, $inputs, -14.0, (float) config('catalog_video.add_sound_gain_db'));
        $out = tempnam(sys_get_temp_dir(), 'soundtrack-').'.wav';

        (new Process([...$arguments, '-filter_complex', $graph, '-map', '[premix]', '-c:a', 'pcm_s16le', '-ar', '44100', $out], timeout: 120))->mustRun();

        return $out;
    }

    /**
     * When sound begins after silence, in seconds (ffmpeg's silencedetect: every silence_end is an onset).
     *
     * @return list<float>
     */
    private function onsets(string $file): array
    {
        $process = new Process(['ffmpeg', '-hide_banner', '-i', $file, '-af', 'silencedetect=noise=-55dB:d=0.15', '-f', 'null', '-']);
        $process->run();
        preg_match_all('/silence_end: ([\d.]+)/', $process->getErrorOutput(), $matches);

        // The last silence ends with the file, which is not a sound.
        $end = $this->streamSeconds($file, 'audio');

        return array_values(array_filter(array_map('floatval', $matches[1]), fn (float $at): bool => $at < $end - 0.05));
    }
}
