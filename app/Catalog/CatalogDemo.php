<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Models\ContentItem;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What a `kind: catalog` item says about its own video (`raw.demo`, docs/social-feed-v1.md): the leaflet's
 * pages to show and the three products to tap on it, with the places and the prices the source gave.
 *
 * It is read, never invented. Everything the video prints — a name, a price, the sum on the list — is
 * here, and the sum has to be the sum of the prices: a total on the screen that is not the total of the
 * rows would be the one claim in the video nobody checked.
 */
final readonly class CatalogDemo
{
    /** The video taps exactly three products; a layout for another number is a different video. */
    public const TAPS = 3;

    /**
     * @param  list<array{number: int, url: string, width: int, height: int}>  $pages  In the order they are shown, the cover first.
     * @param  list<array{page: int, card_id: string, product_id: string, brand: string|null, name: string, variant: string|null, price_cents: int, currency: string, unit: array{amount: float|int, measure: string}|null, crop: string, bbox: array{x: float, y: float, w: float, h: float}, point: array{x: float, y: float}}>  $taps  In the order they are tapped.
     */
    public function __construct(
        public string $catalogId,
        public string $chain,
        public string $chainName,
        public ?string $chainPhrase,
        public CarbonImmutable $validFrom,
        public CarbonImmutable $validTo,
        public int $pageCount,
        public int $productCount,
        public string $currency,
        public string $locale,
        public array $pages,
        public array $taps,
        public int $totalCents,
        public ?string $appUrl,
        public ?string $campaign,
    ) {}

    /**
     * @throws InvalidArgumentException With every reason the block cannot be made into a video.
     */
    public static function fromItem(ContentItem $item): self
    {
        $demo = data_get($item->raw, 'demo');

        if (! is_array($demo)) {
            throw new InvalidArgumentException('Stavka nema blok raw.demo: bez njega nema tri proizvoda za dodirnuti.');
        }

        return self::fromArray($demo);
    }

    /**
     * @param  array<string, mixed>  $demo
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $demo): self
    {
        $problems = self::problems($demo);

        if ($problems !== []) {
            throw new InvalidArgumentException('raw.demo ne valja: '.implode(' ', $problems));
        }

        $pages = array_map(fn (array $page): array => [
            'number' => (int) $page['number'],
            'url' => (string) $page['url'],
            'width' => (int) $page['width'],
            'height' => (int) $page['height'],
        ], array_values($demo['pages']));

        $taps = array_map(fn (array $tap): array => [
            'page' => (int) $tap['page'],
            'card_id' => (string) ($tap['card_id'] ?? ''),
            'product_id' => (string) ($tap['product_id'] ?? ''),
            'brand' => filled($tap['brand'] ?? null) ? mb_trim((string) $tap['brand']) : null,
            'name' => mb_trim((string) $tap['name']),
            'variant' => filled($tap['variant'] ?? null) ? mb_trim((string) $tap['variant']) : null,
            'price_cents' => (int) $tap['price_cents'],
            'currency' => (string) ($tap['currency'] ?? $demo['currency'] ?? 'EUR'),
            'unit' => is_array($tap['unit'] ?? null) && is_numeric($tap['unit']['amount'] ?? null) && filled($tap['unit']['measure'] ?? null)
                ? ['amount' => $tap['unit']['amount'] + 0, 'measure' => (string) $tap['unit']['measure']]
                : null,
            'crop' => (string) $tap['crop'],
            'bbox' => self::box($tap['bbox']),
            'point' => ['x' => (float) $tap['point']['x'], 'y' => (float) $tap['point']['y']],
        ], array_values($demo['taps']));

        return new self(
            catalogId: (string) $demo['catalog_id'],
            chain: (string) $demo['chain'],
            chainName: mb_trim((string) $demo['chain_name']),
            chainPhrase: filled($demo['chain_phrase'] ?? null) ? mb_trim((string) $demo['chain_phrase']) : null,
            validFrom: CarbonImmutable::parse((string) $demo['valid_from'], 'UTC')->startOfDay(),
            validTo: CarbonImmutable::parse((string) $demo['valid_to'], 'UTC')->startOfDay(),
            pageCount: (int) ($demo['page_count'] ?? 0),
            productCount: (int) ($demo['product_count'] ?? 0),
            currency: (string) ($demo['currency'] ?? 'EUR'),
            locale: (string) ($demo['locale'] ?? 'hr-HR'),
            pages: $pages,
            taps: $taps,
            totalCents: (int) $demo['total_cents'],
            appUrl: filled($demo['app_url'] ?? null) ? (string) $demo['app_url'] : null,
            campaign: filled($demo['campaign'] ?? null) ? (string) $demo['campaign'] : null,
        );
    }

    /**
     * Why a `raw.demo` block cannot be a video, in words for the person who owns the feed; empty when it can.
     *
     * @param  array<string, mixed>  $demo
     * @return list<string>
     */
    public static function problems(array $demo): array
    {
        $problems = [];

        foreach (['catalog_id', 'chain', 'chain_name', 'valid_from', 'valid_to'] as $key) {
            if (! filled($demo[$key] ?? null)) {
                $problems[] = "Nedostaje {$key}.";
            }
        }

        foreach (['valid_from', 'valid_to'] as $key) {
            if (filled($demo[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $demo[$key]) !== 1) {
                $problems[] = "{$key} nije datum (YYYY-MM-DD).";
            }
        }

        $pages = $demo['pages'] ?? null;
        $taps = $demo['taps'] ?? null;

        if (! is_array($pages) || $pages === []) {
            return [...$problems, 'Nema stranica letka.'];
        }

        $numbers = [];

        foreach (array_values($pages) as $index => $page) {
            $where = 'Stranica '.($index + 1).': ';

            if (! is_array($page) || ! is_numeric($page['number'] ?? null) || ! is_numeric($page['width'] ?? null) || ! is_numeric($page['height'] ?? null)
                || (int) $page['width'] <= 0 || (int) $page['height'] <= 0) {
                $problems[] = $where.'treba number, width i height.';

                continue;
            }

            if (! self::fetchable($page['url'] ?? null)) {
                $problems[] = $where.'url mora biti apsolutan (http/https), hub ga skida.';
            }

            $numbers[] = (int) $page['number'];
        }

        if (! is_array($taps) || count($taps) !== self::TAPS) {
            return [...$problems, 'Treba točno '.self::TAPS.' proizvoda za dodir, ne '.(is_array($taps) ? count($taps) : 0).'.'];
        }

        $sum = 0;
        $names = [];
        $furthest = -1;
        $order = array_flip($numbers);

        foreach (array_values($taps) as $index => $tap) {
            $where = 'Dodir '.($index + 1).': ';

            if (! is_array($tap)) {
                $problems[] = $where.'nije objekt.';

                continue;
            }

            if (! in_array((int) ($tap['page'] ?? 0), $numbers, true)) {
                $problems[] = $where.'stranica nije među stranicama letka.';
            } elseif (($position = $order[(int) $tap['page']]) < $furthest) {
                // The pages are turned forward, one swipe at a time: a tap on a page already left would be drawn on the wrong one.
                $problems[] = $where.'stranica je prije one s prethodnog dodira; dodiri idu redom stranica.';
            } else {
                $furthest = $position;
            }

            if (mb_trim((string) ($tap['name'] ?? '')) === '') {
                $problems[] = $where.'nema naziv.';
            }

            if (! is_int($tap['price_cents'] ?? null) || $tap['price_cents'] <= 0) {
                $problems[] = $where.'cijena (price_cents) mora biti pozitivan cijeli broj centi.';
            } else {
                $sum += $tap['price_cents'];
            }

            if (! self::fetchable($tap['crop'] ?? null)) {
                $problems[] = $where.'crop mora biti apsolutan url slike.';
            }

            foreach (['x', 'y'] as $axis) {
                $value = $tap['point'][$axis] ?? null;

                if (! is_numeric($value) || $value < 0 || $value > 1) {
                    $problems[] = $where."point.{$axis} mora biti između 0 i 1.";
                }
            }

            if (! is_array($tap['bbox'] ?? null) || array_diff(['x', 'y', 'w', 'h'], array_keys($tap['bbox'])) !== []) {
                $problems[] = $where.'nedostaje bbox (x, y, w, h).';
            }

            $key = mb_strtolower(mb_trim((string) ($tap['name'] ?? '')));

            if (in_array($key, $names, true)) {
                $problems[] = $where.'isti naziv kao drugi dodir.';
            }

            $names[] = $key;
        }

        if (($demo['total_cents'] ?? null) !== $sum) {
            $problems[] = 'total_cents ('.json_encode($demo['total_cents'] ?? null).") nije zbroj cijena dodira ({$sum}).";
        }

        return $problems;
    }

    /**
     * The cover and the pages in between a swipe passes over; every page the taps are on is among them.
     *
     * @return list<int>
     */
    public function tapPages(): array
    {
        return array_values(array_unique(array_map(fn (array $tap): int => $tap['page'], $this->taps)));
    }

    /**
     * "Konzumov katalog" — what the voice says and the title shows. A chain the source gave no phrase for is
     * "katalog trgovine Konzum": less natural, never wrong.
     */
    public function phrase(): string
    {
        return $this->chainPhrase ?? 'katalog trgovine '.$this->chainName;
    }

    /**
     * @param  array<string, mixed>  $box
     * @return array{x: float, y: float, w: float, h: float}
     */
    private static function box(array $box): array
    {
        return ['x' => (float) $box['x'], 'y' => (float) $box['y'], 'w' => (float) $box['w'], 'h' => (float) $box['h']];
    }

    private static function fetchable(mixed $url): bool
    {
        return is_string($url) && preg_match('~^https?://[^\s/]+~i', $url) === 1;
    }
}
