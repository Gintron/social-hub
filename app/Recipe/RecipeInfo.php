<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Enums\ContentKind;
use App\Models\ContentItem;
use InvalidArgumentException;

/**
 * What a `kind: recipe` item says about its dish (`raw.recipe`, docs/social-feed-v1.md § Recept): how many ingredients are
 * on sale, what they come to and what the leaflet saves, the dish's time and where its page is.
 *
 * It is read, never invented, and it never holds a product from a leaflet (no name, brand, price or picture of an offer): the
 * source keeps those for people with access, and a post is as public as the page a visitor who is not signed in sees. The
 * sum is one pack of every ingredient on sale, not the price of the dish, and the saving is against the old prices the leaflet
 * printed; every sentence the hub writes about them says so (RecipeCopy).
 */
final readonly class RecipeInfo
{
    /**
     * A saving smaller than this is not shouted: on a hook it reads as a sticker, and the page of the dish already says the
     * exact figure. Listo itself starts at 20 cents; a post is held to a euro.
     */
    public const MIN_SAVING_CENTS = 100;

    /** A dish is told with at most this many main ingredients, when the source sends them and the brand wants them shown. */
    public const MAX_HIGHLIGHTS = 4;

    /**
     * @param  list<string>  $chains  As the source names them, with a regional edition ("Billa (Wien)").
     * @param  list<string>  $highlights  Main ingredients on sale, without a brand; empty when the source did not send them.
     * @param  array{url: string, width: int, height: int}|null  $leafletCover  The cover of the shop's leaflet, a public page of the catalogue (it says
     *                                                                          nothing about which products are in the dish); null when the source sent none.
     */
    public function __construct(
        public string $recipeId,
        public int $minutes,
        public int $servings,
        public int $ingredientsTotal,
        public int $ingredientsOnSale,
        public int $totalCents,
        public int $savingCents,
        public string $currency,
        public string $validTo,
        public string $chain,
        public array $chains,
        public bool $aiImage,
        public ?string $pageUrl,
        public ?string $appUrl,
        public ?string $campaign,
        public array $highlights,
        public ?array $leafletCover = null,
    ) {}

    /**
     * @throws InvalidArgumentException With the reason the block cannot be a post.
     */
    public static function fromItem(ContentItem $item): self
    {
        if ($item->kind !== ContentKind::Recipe) {
            throw new InvalidArgumentException('Stavka nije recept.');
        }

        $block = data_get($item->raw, 'recipe');

        if (! is_array($block)) {
            throw new InvalidArgumentException('Stavka nema blok raw.recipe: bez njega nema brojki za objavu jela.');
        }

        return self::fromArray($block);
    }

    /**
     * Why an item cannot be posted as a dish, or nothing when it can. A kind other than recipe has no such problem here.
     *
     * @return list<string>
     */
    public static function problemsOf(ContentItem $item): array
    {
        $block = data_get($item->raw, 'recipe');

        return is_array($block) ? self::problems($block) : ['Stavka nema blok raw.recipe.'];
    }

    public static function tryFromItem(ContentItem $item): ?self
    {
        try {
            return self::fromItem($item);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $block
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $block): self
    {
        $problems = self::problems($block);

        if ($problems !== []) {
            throw new InvalidArgumentException('raw.recipe ne valja: '.implode(' ', $problems));
        }

        $highlights = array_values(array_unique(array_filter(
            array_map(fn (mixed $name): string => mb_trim((string) $name), (array) ($block['highlights'] ?? [])),
            fn (string $name): bool => $name !== '',
        )));

        return new self(
            recipeId: (string) $block['recipe_id'],
            minutes: (int) $block['minutes'],
            servings: (int) $block['servings'],
            ingredientsTotal: (int) $block['ingredients_total'],
            ingredientsOnSale: (int) $block['ingredients_on_sale'],
            totalCents: (int) $block['total_cents'],
            savingCents: (int) ($block['saving_cents'] ?? 0),
            currency: (string) ($block['currency'] ?? 'EUR'),
            validTo: (string) $block['valid_to'],
            chain: (string) ($block['chain'] ?? ''),
            chains: array_values(array_map('strval', (array) ($block['chains'] ?? []))),
            // A source that does not say is taken to have drawn the picture: a label that is not needed costs a word, one that is
            // missing is a claim the post should not make.
            aiImage: ! array_key_exists('ai_image', $block) || (bool) $block['ai_image'],
            pageUrl: filled($block['page_url'] ?? null) ? (string) $block['page_url'] : null,
            appUrl: filled($block['app_url'] ?? null) ? (string) $block['app_url'] : null,
            campaign: filled($block['campaign'] ?? null) ? (string) $block['campaign'] : null,
            highlights: array_slice($highlights, 0, self::MAX_HIGHLIGHTS),
            leafletCover: self::cover($block['leaflet_cover'] ?? null),
        );
    }

    /**
     * Every reason the block cannot be a post, none left out.
     *
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    public static function problems(array $block): array
    {
        $problems = [];
        $whole = fn (string $key, int $min, int $max): bool => is_int($block[$key] ?? null) && $block[$key] >= $min && $block[$key] <= $max;

        if (! is_string($block['recipe_id'] ?? null) || mb_trim($block['recipe_id']) === '') {
            $problems[] = 'recipe_id nedostaje.';
        }

        foreach ([['minutes', 1, 1440], ['servings', 1, 50], ['ingredients_total', 2, 60], ['total_cents', 0, 10_000_000]] as [$key, $min, $max]) {
            if (! $whole($key, $min, $max)) {
                $problems[] = "{$key} mora biti cijeli broj od {$min} do {$max}.";
            }
        }

        if (! $whole('ingredients_on_sale', 2, 60)) {
            // A dish with fewer than two ingredients on sale is not "from the offers": the source does not send it, and nor does the hub post it.
            $problems[] = 'ingredients_on_sale mora biti cijeli broj od 2 do 60.';
        } elseif (is_int($block['ingredients_total'] ?? null) && $block['ingredients_on_sale'] > $block['ingredients_total']) {
            $problems[] = 'ingredients_on_sale ne može biti veći od ingredients_total.';
        }

        if (array_key_exists('saving_cents', $block) && ! $whole('saving_cents', 0, 10_000_000)) {
            $problems[] = 'saving_cents mora biti cijeli broj ≥ 0.';
        }

        if (is_int($block['saving_cents'] ?? null) && is_int($block['total_cents'] ?? null) && $block['saving_cents'] > 0 && $block['saving_cents'] > $block['total_cents'] * 4) {
            // The old prices of the same ingredients cannot be several times what they cost now; a figure like that is a mistake in the source.
            $problems[] = 'saving_cents je nevjerojatno velik u odnosu na total_cents.';
        }

        if (! is_string($block['valid_to'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $block['valid_to']) !== 1) {
            $problems[] = 'valid_to mora biti datum YYYY-MM-DD.';
        }

        return $problems;
    }

    /**
     * "8,96 €": the way the source writes amounts, in either language.
     */
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }

    /**
     * The saving as a post says it: only when it is worth a euro, else nothing (the page of the dish has the exact figure).
     */
    public function shownSavingCents(): ?int
    {
        return $this->savingCents >= self::MIN_SAVING_CENTS ? $this->savingCents : null;
    }

    /**
     * The shops the dish is from, as they are written on a screen: a country after the name is dropped ("Lidl Österreich"
     * → "Lidl"); the regional edition stays ("Billa (Wien)") because a Viennese price is not a price in Graz. Spoken, the
     * edition goes as well: a voice does not read brackets.
     *
     * @return list<string>
     */
    public function shops(bool $spoken = false): array
    {
        $names = array_map(function (string $name) use ($spoken): string {
            $name = (string) preg_replace('/\s+(?:Österreich|Austria|Hrvatska|Croatia)(?=\s*\(|$)/iu', '', mb_trim($name));

            return $spoken ? mb_trim((string) preg_replace('/\s*\([^)]*\)/u', '', $name)) : $name;
        }, $this->chains);

        return array_values(array_unique(array_filter($names, fn (string $name): bool => $name !== '')));
    }

    /**
     * The cover of the leaflet, when the source sent one that can be shown. A decoration, never a condition: one that does not hold up
     * is no cover, and the dish is told without it.
     *
     * @return array{url: string, width: int, height: int}|null
     */
    private static function cover(mixed $cover): ?array
    {
        if (! is_array($cover) || ! is_string($cover['url'] ?? null) || preg_match('~^https?://~i', $cover['url']) !== 1
            || ! is_int($cover['width'] ?? null) || ! is_int($cover['height'] ?? null) || $cover['width'] < 1 || $cover['height'] < 1) {
            return null;
        }

        return ['url' => $cover['url'], 'width' => $cover['width'], 'height' => $cover['height']];
    }
}
