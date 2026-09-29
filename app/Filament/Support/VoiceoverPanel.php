<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Brand;
use App\Models\Voiceover;
use App\Voiceover\ElevenLabsClient;
use App\Voiceover\SpokenCroatian;
use App\Voiceover\Synthesizer;
use App\Voiceover\VoiceoverException;
use App\Voiceover\VoiceoverSettings;
use Illuminate\Support\Facades\Cache;

/**
 * What the panel needs from ElevenLabs, without ever making a page wait for it: the voices to choose
 * from, whether the connection works, and a sample of a voice.
 *
 * A failure is remembered for a minute, so an unreachable API costs the first page one short wait and
 * the following ones nothing.
 */
final class VoiceoverPanel
{
    public const SAMPLE = 'Kruh bijeli 500 g u trgovini Konzum za 1,49 €. Popust 25 %. Vrijedi do 30.09.2026.';

    private const VOICES_FAILED = 'voiceover.panel.voices_failed';

    private const SUBSCRIPTION = 'voiceover.panel.subscription';

    /**
     * The account's voices as `voice_id => label`; empty when there is no key or the list cannot be read.
     *
     * @return array<string, string>
     */
    public static function voices(): array
    {
        $client = app(ElevenLabsClient::class);

        if (! $client->configured() || Cache::has(self::VOICES_FAILED)) {
            return [];
        }

        try {
            return $client->voices();
        } catch (VoiceoverException $e) {
            Cache::put(self::VOICES_FAILED, $e->getMessage(), 60);

            return [];
        }
    }

    public static function voicesError(): ?string
    {
        $error = Cache::get(self::VOICES_FAILED);

        return is_string($error) ? $error : null;
    }

    /**
     * One line on whether the hub can speak at all and how much of the plan is left.
     */
    public static function status(): string
    {
        $client = app(ElevenLabsClient::class);

        if (! $client->configured()) {
            return 'ElevenLabs nije spojen: postavi ELEVENLABS_API_KEY u .env poslužitelja (docs/voiceover.md). Do tada se videi rade kao i prije.';
        }

        /** @var array<string, mixed> $subscription */
        $subscription = Cache::remember(self::SUBSCRIPTION, 300, function () use ($client): array {
            try {
                return $client->subscription();
            } catch (VoiceoverException $e) {
                return ['error' => $e->getMessage(), 'code' => $e->errorCode];
            }
        });

        if (isset($subscription['error'])) {
            // The key may be scoped to speech only; that is a working key.
            return ($subscription['code'] ?? null) === 'missing_permissions'
                ? 'ElevenLabs je spojen (ključ ne smije čitati pretplatu, pa se preostali znakovi ne vide).'
                : 'ElevenLabs: '.$subscription['error'];
        }

        $left = max(0, (int) $subscription['character_limit'] - (int) $subscription['character_count']);
        $reset = $subscription['next_character_count_reset_unix'] !== null
            ? ', obnova '.date('d.m.Y.', (int) $subscription['next_character_count_reset_unix'])
            : '';

        return sprintf(
            'ElevenLabs je spojen · plan %s · ostalo %s od %s znakova%s.',
            $subscription['tier'] ?? '—',
            number_format($left, 0, ',', '.'),
            number_format((int) $subscription['character_limit'], 0, ',', '.'),
            $reset,
        );
    }

    /**
     * A sample of the voice as the brand form has it now — before it is saved — and the words as the voice
     * receives them, so the way numbers are spelled can be checked by ear and by eye.
     *
     * @param  array<string, mixed>  $state  The form's `voiceover` values.
     * @return array{clip: Voiceover, spoken: string}
     *
     * @throws VoiceoverException
     */
    public static function sample(array $state, string $text, ?Brand $brand = null): array
    {
        $settings = VoiceoverSettings::fromArray($state);

        if (! $settings->canSpeak()) {
            throw new VoiceoverException('Odaberi glas i postavi ELEVENLABS_API_KEY da bi se glas mogao preslušati.', 'not_configured');
        }

        $spoken = app(SpokenCroatian::class)->speak($text, $settings->pronunciations);

        if ($spoken === '') {
            throw new VoiceoverException('Nema što izgovoriti.', 'empty_script');
        }

        return ['clip' => app(Synthesizer::class)->clip($spoken, $settings, $brand), 'spoken' => $spoken];
    }
}
