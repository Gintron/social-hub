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
    /** How many rows of a comparison fit on a slide and in a first glance. */
    public const COMPARISON_ROWS = 5;

    public function __construct(private readonly RemoteImageCache $images) {}

    public static function defaultEmoji(ContentKind $kind): string
    {
        return match ($kind) {
            ContentKind::Job => '💼',
            ContentKind::Deal => '🛒',
            ContentKind::Article => '📰',
            ContentKind::Event => '📅',
            ContentKind::Generic => '📌',
            ContentKind::Comparison => '⚖️',
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
        $comparison = $item->kind === ContentKind::Comparison;
        // A comparison's facts are its rows, and a ranking cut at four hides the dearest shop.
        $facts = array_slice($item->facts ?? [], 0, $comparison ? self::COMPARISON_ROWS : 4);
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
            // Whose offer this is: the chain, the employer, the publisher. The contract carries it
            // the same way for every kind (`subtitle` + `images[role=logo]`), so no template has to
            // know what a retailer is. Deliberately without a fallback to the brand's own logo: the
            // hub passes the offer on, it does not sell it, and its mark belongs in the footer.
            'provider' => $this->provider($item),
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
            'rows' => $comparison ? $this->rows($item, $facts) : [],
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
        // The deepest cut in the roundup, from the generic price field: "do −60 %" sells the swipe.
        $deepest = (int) $items->map(fn (ContentItem $item): int => (int) (($item->price ?? [])['discount_pct'] ?? 0))->max();

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
            // A roundup speaks for one chain only when every item in it does.
            'provider' => $this->sharedProvider($items),
            'expires_at' => null,
            'badge' => $deepest > 0 ? "do −{$deepest} %" : null,
            // Items without a picture are left out rather than shown as empty tiles.
            'thumbnails' => $items
                ->map(fn (ContentItem $item): ?string => $this->images->dataUri($item->imageUrl('primary')))
                ->filter()
                ->take(4)
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
            // What the brand does and where to get it, for the end card: the slides before it show
            // someone else's offer, so without these a viewer learns the price and never the reason.
            'pitch' => filled(data_get($brand->voice, 'pitch')) ? (string) data_get($brand->voice, 'pitch') : null,
            'cta_note' => filled(data_get($brand->voice, 'cta_note')) ? (string) data_get($brand->voice, 'cta_note') : null,
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

    /**
     * Whose offer this is, and in what shape its mark comes.
     *
     * @return array{name: string|null, logo: string|null, logo_ratio: float|null}
     */
    private function provider(ContentItem $item): array
    {
        $logo = $item->imageUrl('logo');

        return [
            'name' => filled($item->subtitle) ? (string) $item->subtitle : null,
            'logo' => $this->images->dataUri($logo),
            // Wordmark or badge: the plaque gives a badge the height it cannot get in width.
            'logo_ratio' => $this->images->aspectRatio($logo),
        ];
    }

    /**
     * A comparison's ranking, one row per fact in the feed's order (Social Feed v1: `kind` =
     * `comparison`). `raw.rows` may add what the row shows beside its figure — the product, its
     * pack price, the provider's mark and picture — but only when it lines up with the facts row
     * for row; a mismatched extra would put one shop's product next to another shop's price.
     *
     * @param  list<array{label: string, value: string}>  $facts
     * @return list<array{name: string, value: string, title: string|null, price: string|null, image: string|null, provider: array{name: string, logo: string|null, logo_ratio: float|null}}>
     */
    private function rows(ContentItem $item, array $facts): array
    {
        $extras = data_get($item->raw, 'rows');
        $extras = is_array($extras) ? array_values($extras) : [];

        $rows = [];

        foreach (array_values($facts) as $index => $fact) {
            $extra = is_array($extras[$index] ?? null) ? $extras[$index] : [];

            if (filled($extra['chain_name'] ?? null) && $extra['chain_name'] !== $fact['label']) {
                $extra = [];
            }

            $logo = is_string($extra['logo'] ?? null) ? $extra['logo'] : null;
            $image = is_string($extra['image'] ?? null) ? $extra['image'] : null;
            $cents = $extra['price_cents'] ?? null;

            $rows[] = [
                'name' => (string) $fact['label'],
                'value' => (string) $fact['value'],
                'title' => filled($extra['title'] ?? null) ? (string) $extra['title'] : null,
                'price' => is_numeric($cents) ? number_format(((int) $cents) / 100, 2, ',', '.').' €' : null,
                'image' => $this->images->dataUri($image),
                'provider' => [
                    'name' => (string) $fact['label'],
                    'logo' => $this->images->dataUri($logo),
                    'logo_ratio' => $this->images->aspectRatio($logo),
                ],
            ];
        }

        return $rows;
    }

    /**
     * The provider every item in the set shares, or nothing when they come from more than one.
     * A mixed roundup must not be badged with one chain's logo — that would claim the others' deals
     * for it.
     *
     * @param  \Illuminate\Support\Collection<int, ContentItem>  $items
     * @return array{name: string|null, logo: string|null, logo_ratio: float|null}
     */
    private function sharedProvider(\Illuminate\Support\Collection $items): array
    {
        $names = $items->map(fn (ContentItem $item): string => mb_trim((string) $item->subtitle))->unique();

        if ($names->count() !== 1 || blank($names->first())) {
            return ['name' => null, 'logo' => null, 'logo_ratio' => null];
        }

        $logo = $items->map(fn (ContentItem $item): ?string => $item->imageUrl('logo'))->filter()->first();

        return [
            'name' => (string) $names->first(),
            'logo' => $this->images->dataUri($logo),
            'logo_ratio' => $this->images->aspectRatio($logo),
        ];
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
