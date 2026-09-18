<?php

declare(strict_types=1);

namespace App\Drafting;

use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Rendering\VideoRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One recurring roundup of a brand ("Top 7 akcija u Kauflandu", every Wednesday at 18:30), as set
 * in the panel (`brands.digests`). A brand has as many as it likes; each is built once a day.
 *
 * `tag` narrows the items to one of the feed's own tags (a chain, a city) — a generic field of the
 * contract, so a new roundup is a panel entry, never a branch in code.
 */
final readonly class DigestSeries
{
    public const DEFAULT_TIME = '19:00';

    public const DEFAULT_COUNT = 5;

    /**
     * @param  list<int>  $days  ISO weekdays, 1 = Monday.
     */
    public function __construct(
        public string $key,
        public bool $enabled,
        public string $name,
        public ?string $headline,
        public array $days,
        public string $time,
        public int $count,
        public ContentKind $kind,
        public ?string $tag,
        public ContentFormat $metaFormat,
        public ContentFormat $tiktokFormat,
        public float $secondsPerSlide,
    ) {}

    /**
     * @param  array<string, mixed>  $data  One repeater item as the panel saves it.
     */
    public static function fromArray(array $data, int $position = 0): self
    {
        $formats = (array) ($data['formats'] ?? []);

        return new self(
            key: filled($data['key'] ?? null) ? (string) $data['key'] : 'series-'.$position,
            enabled: (bool) ($data['enabled'] ?? false),
            name: filled($data['name'] ?? null) ? (string) $data['name'] : 'Pregled',
            headline: filled($data['headline'] ?? null) ? mb_trim((string) $data['headline']) : null,
            days: array_values(array_unique(array_map('intval', (array) ($data['days'] ?? [])))),
            time: self::time((string) ($data['time'] ?? '')),
            count: max(2, min(DigestBuilder::MAX_ITEMS, (int) ($data['count'] ?? 0) ?: self::DEFAULT_COUNT)),
            kind: ContentKind::tryFrom((string) ($data['kind'] ?? '')) ?? ContentKind::Job,
            tag: filled($data['tag'] ?? null) ? mb_strtolower(mb_trim((string) $data['tag'])) : null,
            metaFormat: self::format($formats['meta'] ?? null),
            tiktokFormat: self::format($formats['tiktok'] ?? null),
            secondsPerSlide: max(1.5, min(5.0, (float) ($data['seconds_per_slide'] ?? VideoRenderer::DEFAULT_SECONDS_PER_SLIDE))),
        );
    }

    /**
     * The single digest a brand had before series (`brands.digest`), stored as its first series.
     * Null when it was never set up.
     *
     * @param  array<string, mixed>  $digest
     * @return array<string, mixed>|null
     */
    public static function fromLegacy(array $digest): ?array
    {
        if (! ($digest['enabled'] ?? false) && blank($digest['days'] ?? null)) {
            return null;
        }

        return [
            'key' => (string) Str::uuid(),
            'enabled' => (bool) ($digest['enabled'] ?? false),
            'name' => 'Pregled tjedna',
            'headline' => null,
            'days' => array_values((array) ($digest['days'] ?? [])),
            'time' => $digest['time'] ?? self::DEFAULT_TIME,
            'count' => $digest['count'] ?? self::DEFAULT_COUNT,
            'kind' => $digest['kind'] ?? ContentKind::Job->value,
            'tag' => null,
            // It went out as a carousel, and keeps doing so.
            'formats' => ['meta' => ContentFormat::Carousel->value, 'tiktok' => ContentFormat::Carousel->value],
            'seconds_per_slide' => VideoRenderer::DEFAULT_SECONDS_PER_SLIDE,
        ];
    }

    public function isDueOn(CarbonImmutable $local): bool
    {
        return in_array($local->dayOfWeekIso, $this->days, true);
    }

    public function at(CarbonImmutable $local): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->time));

        return $local->setTime($hour, $minute);
    }

    /**
     * A Reel on Facebook and Instagram, a photo carousel or a video on TikTok — whatever the series
     * asks for, as long as the channel takes it; anything else falls back to a carousel.
     */
    public function formatFor(Platform $platform): ContentFormat
    {
        $format = $platform === Platform::TikTok ? $this->tiktokFormat : $this->metaFormat;

        return in_array($format, $platform->formats(), true) ? $format : ContentFormat::Carousel;
    }

    private static function time(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return self::DEFAULT_TIME;
        }

        return sprintf('%02d:%02d', min(23, (int) $matches[1]), min(59, (int) $matches[2]));
    }

    private static function format(mixed $value): ContentFormat
    {
        $format = ContentFormat::tryFrom((string) $value);

        return $format === ContentFormat::Video ? ContentFormat::Video : ContentFormat::Carousel;
    }
}
