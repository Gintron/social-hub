<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Models\Brand;

/**
 * A brand's narrator, as set in the panel (`brands.voiceover`) with the hub's defaults filled in.
 *
 * Everything that changes how a clip *sounds* — the voice, the model, the voice settings — goes into
 * the cache key of a spoken clip; everything that only changes what is *said* (the closing line, the
 * pronunciation list, the IPA put into the text) is applied before the text is hashed, so it needs no place there.
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
     * @param  string  $ipa  How IPA is put into the text the voice reads (Ipa::STYLES); `off` speaks the text as it is. See ipaStyle() for what is used.
     * @param  array<string, string>  $words  Written => IPA, for the words a person has heard the voice say wrongly.
     * @param  bool  $ipaAuto  Also have a model write the IPA of other words (an experiment).
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
        public string $ipa = Ipa::OFF,
        public array $words = [],
        public bool $ipaAuto = false,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data  `brands.voiceover`
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $models = array_keys((array) config('elevenlabs.models', []));
        $model = (string) ($data['model'] ?? '');

        $words = [];

        foreach ((array) ($data['words'] ?? []) as $row) {
            $find = is_array($row) ? mb_trim(Ipa::normalize((string) ($row['find'] ?? ''))) : '';
            $ipa = is_array($row) ? Ipa::sanitize((string) ($row['ipa'] ?? '')) : null;

            // One word, in letters; what a person typed as its IPA is only held to be something that can be put in a text.
            if (preg_match('/^\p{L}+$/u', $find) === 1 && $ipa !== null) {
                $words[$find] = $ipa;
            }
        }

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
            model: in_array($model, $models, true) ? $model : (string) config('elevenlabs.model', 'eleven_v4'),
            stability: is_numeric($data['stability'] ?? null) ? max(0.0, min(1.0, (float) $data['stability'])) : null,
            speed: is_numeric($data['speed'] ?? null) ? max(self::MIN_SPEED, min(self::MAX_SPEED, (float) $data['speed'])) : null,
            outro: filled($data['outro'] ?? null) ? mb_trim((string) $data['outro']) : null,
            pronunciations: $pronunciations,
            music: array_key_exists((string) ($data['music'] ?? ''), self::MUSIC_LEVELS) ? (string) $data['music'] : self::DEFAULT_MUSIC,
            ipa: in_array($data['ipa'] ?? null, Ipa::STYLES, true) ? (string) $data['ipa'] : self::defaultIpa(),
            words: $words,
            ipaAuto: array_key_exists('ipa_auto', $data) && $data['ipa_auto'] !== null && $data['ipa_auto'] !== '' ? (bool) $data['ipa_auto'] : self::defaultIpaAuto(),
        );
    }

    /**
     * What a brand that has not chosen gets (`VOICEOVER_IPA`). It is a way of writing, not a switch: with no words of its
     * own and no model asked, nothing is written.
     */
    public static function defaultIpa(): string
    {
        $default = config('elevenlabs.ipa', Ipa::TAG);

        return in_array($default, Ipa::STYLES, true) ? (string) $default : Ipa::TAG;
    }

    /**
     * Whether a brand that has not chosen has a model write the IPA of other words too (`VOICEOVER_IPA_AUTO`).
     */
    public static function defaultIpaAuto(): bool
    {
        return (bool) config('elevenlabs.ipa_auto', false);
    }

    public static function forBrand(Brand $brand): self
    {
        return self::fromArray($brand->voiceover);
    }

    /**
     * A voice is chosen, there is a key to speak it with and — if the brand has a model write IPA — a key for that model.
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
            $this->autoIpa() && blank(config('openai.api_key')) => 'OPENAI_API_KEY nije postavljen (model piše izgovor riječi u IPA; ili to isključi na brendu: Voice-over → Izgovor)',
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

    /**
     * Whether IPA put into the text is sent to this brand's model: only to the models listed in `elevenlabs.ipa_models`,
     * which is v4 alone (checked live, 2026-10-02). For any other model it is not known what the voice makes of IPA, and
     * IPA a voice does not read is read aloud as noise, so the line is sent as it is.
     */
    public function takesIpa(): bool
    {
        return in_array($this->model, (array) config('elevenlabs.ipa_models', []), true);
    }

    /**
     * The way the IPA is put into the text: what the brand chose, or `off` when its model cannot read it.
     */
    public function ipaStyle(): string
    {
        return $this->takesIpa() ? $this->ipa : Ipa::OFF;
    }

    /**
     * A model is to write IPA for more words than the brand's own (and can: the style is on).
     */
    public function autoIpa(): bool
    {
        return $this->ipaAuto && $this->ipaStyle() !== Ipa::OFF;
    }

    /**
     * The brand has words with an IPA, or has a model write them, that its model would not read — which a person should be told.
     */
    public function ipaIgnored(): bool
    {
        return ($this->words !== [] || $this->ipaAuto) && $this->ipa !== Ipa::OFF && ! $this->takesIpa();
    }

    public function withVoice(string $voiceId): self
    {
        return $this->copy(['voiceId' => $voiceId]);
    }

    /**
     * @param  array<string, string>|null  $words  Replaces the brand's own words; null keeps them.
     */
    public function withIpa(string $style, ?array $words = null, ?bool $auto = null): self
    {
        return $this->copy(['ipa' => $style, 'words' => $words ?? $this->words, 'ipaAuto' => $auto ?? $this->ipaAuto]);
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

        return [
            'stability' => round($stability, 2),
            'similarity_boost' => (float) ($defaults['similarity_boost'] ?? 0.75),
            'style' => (float) ($defaults['style'] ?? 0.0),
            'use_speaker_boost' => (bool) ($defaults['use_speaker_boost'] ?? true),
            'speed' => round(max(self::MIN_SPEED, min(self::MAX_SPEED, $this->speed ?? (float) ($defaults['speed'] ?? 1.0))), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $changes  Constructor arguments by name.
     */
    private function copy(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
