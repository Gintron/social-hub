<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Models\Brand;
use App\Models\Voiceover;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Words in, one spoken clip out — paid for once.
 *
 * A clip is found by a hash of the text and everything that changes how it sounds, so a brand's
 * closing line is spoken the first time a video needs it and read from disk by every video after.
 * A re-render, a channel switched from Reel to TikTok, an editor toggling the voice off and on again
 * all cost nothing.
 */
final class Synthesizer
{
    public function __construct(
        private readonly ElevenLabsClient $client,
        private readonly AudioProbe $probe,
    ) {}

    /**
     * The key of a clip: what is said, by whom, in which model, with which settings.
     */
    public static function hash(string $spoken, VoiceoverSettings $settings): string
    {
        return hash('sha256', json_encode([
            $spoken,
            $settings->voiceId,
            $settings->model,
            $settings->voiceSettings(),
            (string) config('elevenlabs.output_format', 'mp3_44100_128'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function clip(string $spoken, VoiceoverSettings $settings, ?Brand $brand = null): Voiceover
    {
        if ($settings->voiceId === null) {
            throw new VoiceoverException('Brend nema odabran glas.', 'not_configured');
        }

        $hash = self::hash($spoken, $settings);

        if (($existing = $this->stored($hash)) !== null) {
            return $existing;
        }

        // Seven roundups built in the same minute all end on the brand's one closing line. One of them
        // pays for it; the others wait a moment and find it.
        try {
            return Cache::lock("voiceover.speak.{$hash}", 120)->block(
                (int) config('elevenlabs.lock_wait_seconds', 60),
                fn (): Voiceover => $this->stored($hash) ?? $this->speak($spoken, $settings, $brand, $hash),
            );
        } catch (LockTimeoutException $e) {
            throw new VoiceoverException('Isti redak se već izgovara i traje predugo.', 'unavailable', retryable: true, previous: $e);
        }
    }

    private function stored(string $hash): ?Voiceover
    {
        $existing = Voiceover::query()->where('hash', $hash)->first();

        return $existing !== null && $existing->fileExists() ? $existing : null;
    }

    private function speak(string $spoken, VoiceoverSettings $settings, ?Brand $brand, string $hash): Voiceover
    {
        $voiceSettings = $settings->voiceSettings();
        $audio = $this->client->speak($spoken, (string) $settings->voiceId, $settings->model, $voiceSettings);

        $disk = (string) config('elevenlabs.disk', 'local');
        $path = sprintf('voiceovers/%s/%s.mp3', $brand?->slug ?? 'shared', $hash);

        Storage::disk($disk)->put($path, $audio->bytes);

        try {
            $absolute = Storage::disk($disk)->path($path);
            $duration = $this->probe->duration($absolute);
            $loudness = $this->probe->loudness($absolute);
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }

        $attributes = [
            'brand_id' => $brand?->id,
            'voice_id' => $settings->voiceId,
            'model' => $settings->model,
            'text' => $spoken,
            'settings' => $voiceSettings,
            'characters' => mb_strlen($spoken),
            'cost' => $audio->cost,
            'duration_ms' => (int) round($duration * 1000),
            'loudness_lufs' => $loudness['lufs'] ?? null,
            'true_peak_db' => $loudness['peak'] ?? null,
            'disk' => $disk,
            'path' => $path,
            'bytes' => mb_strlen($audio->bytes, '8bit'),
            'request_id' => $audio->requestId,
        ];

        try {
            return Voiceover::query()->updateOrCreate(['hash' => $hash], $attributes);
        } catch (UniqueConstraintViolationException) {
            // Another process recorded the same words a moment ago; theirs is as good as ours.
            return Voiceover::query()->where('hash', $hash)->firstOrFail();
        }
    }
}
