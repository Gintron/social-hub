<?php

declare(strict_types=1);

namespace App\Persona;

use App\Models\Brand;

/**
 * The AI character a brand's channels belong to, as set in the panel (`brands.persona`) with the hub's defaults filled in.
 *
 * A character is a brand: it has the brand's accounts, voice, posting windows and daily limit. This is only what a brand
 * that is a character has on top — who it is, what the channel is for, how it looks and where it stands — and it is what
 * the topics and the scripts are written from. The hub knows nothing about the field a character talks about; that lives
 * here, in a person's words.
 */
final readonly class Persona
{
    public const LANGUAGES = ['en' => 'English', 'hr' => 'Hrvatski'];

    public const DEFAULT_LANGUAGE = 'en';

    public const DEFAULT_TOPICS_PER_DAY = 3;

    public const MAX_TOPICS_PER_DAY = 8;

    public const DEFAULT_SUGGEST_AT = '07:00';

    public function __construct(
        public bool $enabled,
        public string $language,
        public string $name,
        public string $description,
        public string $goal,
        public string $audience,
        public string $tone,
        public string $appearance,
        public ?string $appearanceImage,
        public string $ambience,
        public ?string $ambienceImage,
        public string $avoid,
        public int $topicsPerDay,
        public string $suggestAt,
    ) {}

    public static function forBrand(Brand $brand): self
    {
        return self::fromArray($brand->persona, $brand->name);
    }

    /**
     * @param  array<string, mixed>|null  $data  `brands.persona`
     */
    public static function fromArray(?array $data, string $brandName = ''): self
    {
        $data ??= [];
        $text = fn (string $key): string => mb_trim((string) ($data[$key] ?? ''));
        $path = fn (string $key): ?string => filled($data[$key] ?? null) && is_string($data[$key]) ? $data[$key] : null;

        $language = (string) ($data['language'] ?? '');
        $suggestAt = (string) ($data['suggest_at'] ?? '');

        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            language: array_key_exists($language, self::LANGUAGES) ? $language : self::DEFAULT_LANGUAGE,
            name: $text('name') !== '' ? $text('name') : $brandName,
            description: $text('description'),
            goal: $text('goal'),
            audience: $text('audience'),
            tone: $text('tone'),
            appearance: $text('appearance'),
            appearanceImage: $path('appearance_image'),
            ambience: $text('ambience'),
            ambienceImage: $path('ambience_image'),
            avoid: $text('avoid'),
            topicsPerDay: max(1, min(self::MAX_TOPICS_PER_DAY, (int) ($data['topics_per_day'] ?? self::DEFAULT_TOPICS_PER_DAY))),
            // A picker sends "07:00:00"; anything that is not a time of day is the default.
            suggestAt: preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $suggestAt) === 1 ? mb_substr($suggestAt, 0, 5) : self::DEFAULT_SUGGEST_AT,
        );
    }

    /**
     * Whether there is enough to write from: a character nobody described gets generic topics, which is worse than none.
     */
    public function isReady(): bool
    {
        return $this->enabled && $this->description !== '' && $this->goal !== '';
    }

    public function languageName(): string
    {
        return self::LANGUAGES[$this->language];
    }

    /**
     * The character as the model reads it, the same block in every request about it — the stable start of a prompt is
     * what OpenAI caches.
     */
    public function brief(): string
    {
        $lines = [
            "Name: {$this->name}",
            "Who they are: {$this->description}",
            "What the channel is for: {$this->goal}",
        ];

        foreach ([
            'Audience' => $this->audience,
            'Tone' => $this->tone,
            'Looks' => $this->appearance,
            'Where they are filmed' => $this->ambience,
            'Never talk about' => $this->avoid,
        ] as $label => $value) {
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }

        $lines[] = "Language of everything you write: {$this->languageName()}";

        return implode("\n", $lines);
    }
}
