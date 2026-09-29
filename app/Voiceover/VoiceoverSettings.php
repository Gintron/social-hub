<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Models\Brand;

/**
 * A brand's narrator, as set in the panel (`brands.voiceover`) with the hub's defaults filled in.
 *
 * Everything that changes how a clip *sounds* — the voice, the model, the voice settings — goes into
 * the cache key of a spoken clip; everything that only changes what is *said* (the closing line, the
 * pronunciation list, the stress marks) is applied before the text is hashed, so it needs no place there.
 */
final readonly class VoiceoverSettings
{
    /** How far below the loudness-normalised voice the music plays, in dB. */
    public const MUSIC_LEVELS = ['quiet' => -20.0, 'medium' => -14.0, 'loud' => -8.0];

    public const DEFAULT_MUSIC = 'medium';

    public const MIN_SPEED = 0.7;

    public const MAX_SPEED = 1.2;

    /**
     * @param  array<string, string>  $pronunciations  Written => spoken, for this brand's names.
     * @param  string  $accents  How the stress a model marks is told to the voice (Stress::STYLES); `off` speaks the text as it is.
     */
    public function __construct(
        public bool $enabled,
        public ?string $voiceId,
        public string $model,
        public ?float $stability,
        public ?float $speed,
        public ?string $outro,
        public array $pronunciations,
        public string $music,
        public string $accents = Stress::ACUTE,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data  `brands.voiceover`
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $models = array_keys((array) config('elevenlabs.models', []));
        $model = (string) ($data['model'] ?? '');

        $pronunciations = [];

        foreach ((array) ($data['pronunciations'] ?? []) as $row) {
            $find = is_array($row) ? mb_trim((string) ($row['find'] ?? '')) : '';
            $say = is_array($row) ? mb_trim((string) ($row['say'] ?? '')) : '';

            if ($find !== '' && $say !== '') {
                $pronunciations[$find] = $say;
            }
        }

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            voiceId: filled($data['voice_id'] ?? null) ? mb_trim((string) $data['voice_id']) : null,
            model: in_array($model, $models, true) ? $model : (string) config('elevenlabs.model', 'eleven_multilingual_v2'),
            stability: is_numeric($data['stability'] ?? null) ? max(0.0, min(1.0, (float) $data['stability'])) : null,
            speed: is_numeric($data['speed'] ?? null) ? max(self::MIN_SPEED, min(self::MAX_SPEED, (float) $data['speed'])) : null,
            outro: filled($data['outro'] ?? null) ? mb_trim((string) $data['outro']) : null,
            pronunciations: $pronunciations,
            music: array_key_exists((string) ($data['music'] ?? ''), self::MUSIC_LEVELS) ? (string) $data['music'] : self::DEFAULT_MUSIC,
            accents: in_array($data['accents'] ?? null, Stress::STYLES, true) ? (string) $data['accents'] : self::defaultAccents(),
        );
    }

    /**
     * What a brand that has not chosen gets (`VOICEOVER_ACCENTS`).
     */
    public static function defaultAccents(): string
    {
        $default = config('elevenlabs.accents', Stress::ACUTE);

        return in_array($default, Stress::STYLES, true) ? (string) $default : Stress::ACUTE;
    }

    public static function forBrand(Brand $brand): self
    {
        return self::fromArray($brand->voiceover);
    }

    /**
     * A voice is chosen, there is a key to speak it with and — unless the brand has stress marking off —
     * a key for the model that marks it.
     */
    public function canSpeak(): bool
    {
        return $this->whyNot() === null;
    }

    /**
     * What is missing for this brand to have a voice, in words for a person; null when nothing is.
     */
    public function whyNot(): ?string
    {
        return match (true) {
            $this->voiceId === null => 'brend nema odabran glas',
            blank(config('elevenlabs.api_key')) => 'ELEVENLABS_API_KEY nije postavljen',
            $this->accents !== Stress::OFF && blank(config('openai.api_key')) => 'OPENAI_API_KEY nije postavljen (označuje naglaske; ili ih isključi na brendu: Voice-over → Naglasci)',
            default => null,
        };
    }

    /**
     * Videos of this brand are spoken unless a channel says otherwise.
     */
    public function speaksByDefault(): bool
    {
        return $this->enabled && $this->canSpeak();
    }

    public function musicGainDb(): float
    {
        return self::MUSIC_LEVELS[$this->music];
    }

    /**
     * The `voice_settings` of a request.
     *
     * @return array{stability: float, similarity_boost: float, style: float, use_speaker_boost: bool, speed: float}
     */
    public function voiceSettings(): array
    {
        $defaults = (array) config('elevenlabs.voice_settings', []);
        $stability = $this->stability ?? (float) ($defaults['stability'] ?? 0.5);

        // v3 has three stability positions — creative, natural, robust — and no values between them.
        if ($this->model === 'eleven_v3') {
            $stability = collect([0.0, 0.5, 1.0])->sortBy(fn (float $step): float => abs($step - $stability))->first();
        }

        return [
            'stability' => round($stability, 2),
            'similarity_boost' => (float) ($defaults['similarity_boost'] ?? 0.75),
            'style' => (float) ($defaults['style'] ?? 0.0),
            'use_speaker_boost' => (bool) ($defaults['use_speaker_boost'] ?? true),
            'speed' => round(max(self::MIN_SPEED, min(self::MAX_SPEED, $this->speed ?? (float) ($defaults['speed'] ?? 1.0))), 2),
        ];
    }
}
