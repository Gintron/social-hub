<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Drafting\Highlights;
use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;

/**
 * Builds the view-model a kind template receives: item fields plus inlined images and brand theme.
 */
final class TemplateData
{
    public function __construct(private readonly RemoteImageCache $images) {}

    public static function defaultEmoji(ContentKind $kind): string
    {
        return match ($kind) {
            ContentKind::Job => '💼',
            ContentKind::Deal => '🛒',
            ContentKind::Article => '📰',
            ContentKind::Event => '📅',
            ContentKind::Generic => '📌',
        };
    }

    public static function excerpt(?string $text, int $max): ?string
    {
        if (blank($text)) {
            return null;
        }

        // Cut at the "how to apply" section: templates show the pitch, not contact lines.
        $text = preg_split('/\n\s*Način prijave:/u', (string) $text)[0] ?? (string) $text;
        $text = mb_trim(preg_replace('/\s+/u', ' ', str_replace('•', ' •', $text)) ?? '');

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    public static function displayUrl(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $host = parse_url((string) $url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', $host) : null;
    }

    /**
     * Croatian plural: 1 ponuda, 2-4 ponude, 5+ ponuda.
     */
    public static function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }

        return $many;
    }

    /**
     * @param  array<string, mixed>  $overrides  Per-render tweaks (headline, hide_price, …) coming from the UI or the agent.
     * @return array<string, mixed>
     */
    public function forItem(ContentItem $item, Brand $brand, array $overrides = []): array
    {
        $raw = $item->raw ?? [];
        $facts = array_slice($item->facts ?? [], 0, 4);
        $expiresAt = $item->expires_at?->utc()->format('d.m.Y');
        $figure = Highlights::figure($item);

        return array_replace([
            'kind' => $item->kind->value,
            'emoji' => $raw['emoji'] ?? self::defaultEmoji($item->kind),
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'facts' => $facts,
            'badges' => array_slice($item->badges ?? [], 0, 4),
            'price' => $item->price,
            'excerpt' => self::excerpt($item->body_text, 220),
            'url_display' => self::displayUrl($item->url),
            'primary_image' => $this->images->dataUri($item->imageUrl('primary')),
            'logo_image' => $this->images->dataUri($item->imageUrl('logo')) ?? $this->brandLogo($brand),
            // End-of-day expiries arrive as 23:59:59Z; rendering them in the brand's timezone moves
            // them onto the next day, so the card contradicted the source's own "vrijedi do".
            // Dropped entirely when a fact already states it, or the card prints the date twice.
            'expires_at' => self::expiryStatedInFacts($facts, $expiresAt) ? null : $expiresAt,
            // What the hook slide leads with; the captions open with the same (CaptionBuilder::hook()).
            'hook' => [
                'figure' => $figure['value'] ?? null,
                // A deal's discount often arrives as a badge too; the slide says it once.
                'figure_label' => in_array($figure['label'] ?? null, $item->badges ?? [], true) ? null : ($figure['label'] ?? null),
                'figure_old' => $figure['old'] ?? null,
                'points' => Highlights::points($item, 2),
            ],
            'cta_label' => $item->cta['label'] ?? null,
        ], $overrides);
    }

    /**
     * View-model for a digest cover: a headline over thumbnails of the items it collects.
     *
     * @param  \Illuminate\Support\Collection<int, ContentItem>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function forDigest(\Illuminate\Support\Collection $items, Brand $brand, string $headline, ?string $kicker = null, array $overrides = []): array
    {
        return array_replace([
            'kind' => 'digest',
            'emoji' => '📌',
            'kicker' => $kicker,
            'title' => $headline,
            'subtitle' => $items->count().' '.self::plural($items->count(), 'ponuda', 'ponude', 'ponuda'),
            'facts' => [],
            'badges' => [],
            'price' => null,
            'excerpt' => null,
            'url_display' => self::displayUrl($brand->site_url),
            'primary_image' => null,
            'logo_image' => $this->brandLogo($brand),
            'expires_at' => null,
            'thumbnails' => $items->take(4)
                ->map(fn (ContentItem $item): ?string => $this->images->dataUri($item->imageUrl('primary')))
                ->values()
                ->all(),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public function brandTheme(Brand $brand): array
    {
        $colors = $brand->colors ?? [];

        return [
            'name' => $brand->name,
            'site' => self::displayUrl($brand->site_url),
            'logo' => $this->brandLogo($brand),
            'primary' => $colors['primary'] ?? '#1d4ed8',
            'accent' => $colors['accent'] ?? '#f59e0b',
            'text' => $colors['text'] ?? '#111827',
            'muted' => $colors['muted'] ?? '#6b7280',
            'background' => $colors['background'] ?? '#ffffff',
            'surface' => $colors['surface'] ?? '#f3f4f6',
            'cta' => filled(data_get($brand->voice, 'cta')) ? (string) data_get($brand->voice, 'cta') : null,
            // A mark on a transparent background goes white on the primary colour; a filled one
            // (a square with a tick) would become a blank block, so it keeps its colours.
            'logo_filter' => ($colors['logo_footer'] ?? 'white') === 'original' ? 'border-radius: 14px;' : 'filter: brightness(0) invert(1);',
        ];
    }

    /**
     * Does a fact already carry this date? Sites write it their own way — "15.9.2026." against the
     * hub's "15.09.2026" — so compare on the numbers rather than on the formatting.
     *
     * @param  list<array{label: string, value: string}>  $facts
     */
    private static function expiryStatedInFacts(array $facts, ?string $expiry): bool
    {
        if ($expiry === null) {
            return false;
        }

        [$day, $month, $year] = array_map('intval', explode('.', $expiry));
        $needle = $day.'.'.$month.'.'.$year;

        foreach ($facts as $fact) {
            $value = preg_replace('/\s+/', '', (string) ($fact['value'] ?? ''));
            // Strip leading zeros in each number so 15.09.2026 and 15.9.2026. compare equal.
            $value = preg_replace('/\b0+(\d)/', '$1', (string) $value);

            if (str_contains((string) $value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function brandLogo(Brand $brand): ?string
    {
        if (blank($brand->logo_path)) {
            return null;
        }

        $path = (string) $brand->logo_path;

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $this->images->dataUri($path);
        }

        return $this->images->dataUriFromPath(is_file($path) ? $path : storage_path('app/public/'.mb_ltrim($path, '/')));
    }
}
