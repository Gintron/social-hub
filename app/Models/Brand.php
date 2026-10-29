<?php

declare(strict_types=1);

namespace App\Models;

use App\Drafting\DigestSeries;
use App\Persona\Persona;
use App\Support\Wording;
use App\Voiceover\VoiceoverSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A site/brand the hub publishes for (studentski-poslovi, radim.hr, uselisto…).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $site_url
 * @property string|null $logo_path
 * @property array<string, string>|null $colors
 * @property array<string, mixed>|null $voice
 * @property array<int, array{day?: string, from: string, to: string}>|null $posting_windows
 * @property int|null $daily_post_limit
 * @property bool $auto_lineup
 * @property list<string>|null $lineup_priority
 * @property array<array-key, array{path?: string|null, title?: string|null, license?: string|null}>|null $audio_tracks
 * @property array<string, mixed>|null $voiceover
 * @property array<array-key, array<string, mixed>>|null $digests
 * @property array<string, mixed>|null $persona
 * @property string $timezone
 * @property string $language
 */
final class Brand extends Model
{
    use HasFactory;

    /**
     * Tracks are uploaded through the panel onto the public disk, whatever disk rendered media goes to.
     */
    public const AUDIO_DISK = 'public';

    protected $fillable = [
        'slug', 'name', 'site_url', 'logo_path', 'colors', 'voice', 'posting_windows', 'daily_post_limit', 'auto_lineup', 'lineup_priority', 'audio_tracks', 'voiceover', 'digests', 'persona', 'timezone', 'language',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function postDrafts(): HasMany
    {
        return $this->hasMany(PostDraft::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function topicIdeas(): HasMany
    {
        return $this->hasMany(TopicIdea::class);
    }

    /**
     * The language the brand's channels speak, one of Wording::LANGUAGES. Anything else (an empty or unknown value)
     * is Croatian, the language every brand spoke before it had a choice.
     */
    public function languageCode(): string
    {
        return Wording::language($this->language ?? null);
    }

    /**
     * The AI character the brand's channels belong to; switched off for a brand that is not one.
     */
    public function personaSettings(): Persona
    {
        return Persona::forBrand($this);
    }

    /**
     * The brand's recurring roundups, in the order the panel shows them.
     *
     * @return list<DigestSeries>
     */
    public function digestSeries(): array
    {
        $series = [];

        foreach (array_values($this->digests ?? []) as $position => $data) {
            if (is_array($data)) {
                $series[] = DigestSeries::fromArray($data, $position);
            }
        }

        return $series;
    }

    /**
     * The brand's music library, in the order the panel shows it.
     *
     * @return list<array{path: string, title: string, license: string|null}>
     */
    public function audioTracks(): array
    {
        $tracks = [];

        foreach ($this->audio_tracks ?? [] as $track) {
            if (! is_array($track) || blank($track['path'] ?? null)) {
                continue;
            }

            $tracks[] = [
                'path' => (string) $track['path'],
                'title' => filled($track['title'] ?? null) ? (string) $track['title'] : basename((string) $track['path']),
                'license' => filled($track['license'] ?? null) ? (string) $track['license'] : null,
            ];
        }

        return $tracks;
    }

    /**
     * Resolve a choice from the panel or the CLI to a file ffmpeg can read.
     *
     * `none` means silence, `auto` rotates through the library by `$seed` (the draft id, so posts
     * vary but a re-render keeps its track), a number picks that track. Null when there is nothing
     * to play — a missing file silences the video rather than failing the render.
     */
    public function audioTrackPath(string $choice, int $seed = 0): ?string
    {
        $tracks = $this->audioTracks();

        if ($choice === 'none') {
            return null;
        }

        if ($tracks === []) {
            return $choice === 'auto' ? $this->defaultAudioPath() : null;
        }

        $track = match (true) {
            $choice === 'auto' => $tracks[abs($seed) % count($tracks)],
            ctype_digit($choice) => $tracks[(int) $choice] ?? null,
            default => null,
        };

        if ($track === null) {
            return null;
        }

        $path = Storage::disk(self::AUDIO_DISK)->path($track['path']);

        return is_file($path) ? $path : ($choice === 'auto' ? $this->defaultAudioPath() : null);
    }

    /**
     * The brand's narrator: whether its videos are spoken and by which voice.
     */
    public function voiceoverSettings(): VoiceoverSettings
    {
        return VoiceoverSettings::forBrand($this);
    }

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'voice' => 'array',
            'posting_windows' => 'array',
            'audio_tracks' => 'array',
            'voiceover' => 'array',
            'digests' => 'array',
            'persona' => 'array',
            'auto_lineup' => 'boolean',
            'lineup_priority' => 'array',
        ];
    }

    private function defaultAudioPath(): ?string
    {
        $path = config("hub.render.default_audio.{$this->slug}");

        return is_string($path) && is_file($path) ? $path : null;
    }
}
