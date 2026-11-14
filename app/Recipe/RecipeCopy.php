<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Catalog\CatalogCopy;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Support\Wording;
use Carbon\CarbonImmutable;

/**
 * Every word the hub adds to a recipe post, written from what the item carries and in the brand's language.
 *
 * The slides, the voice, the subtitles and the caption say the same sentences, so one place writes them. The dish's own words
 * (its title) are the source's; what is said about the numbers is fixed here and says what they are: the ingredients that are on
 * sale, the sum of one pack of each, the saving against the old prices of the leaflet, and that the picture is drawn by an AI.
 * Nothing here knows a product: the block holds none (RecipeInfo).
 */
final class RecipeCopy
{
    public const HOOK_QUESTION = 'question';

    public const HOOK_FIGURE = 'figure';

    public const HOOK_TIME = 'time';

    /** "Iz letka na tanjur": the cover of the shop's leaflet, then the dish. Only for a dish whose source sent the cover. */
    public const HOOK_LEAFLET = 'leaflet';

    /** `alternate` is the brand's choice of letting the question and the number take turns, so their reach can be compared. */
    public const HOOK_ALTERNATE = 'alternate';

    public const HOOKS = [self::HOOK_QUESTION, self::HOOK_FIGURE, self::HOOK_TIME, self::HOOK_LEAFLET];

    /** A dish this quick may be told by its time ("Gotovo za 25 minuta"); a long one would promise a weeknight it does not keep. */
    public const QUICK_MINUTES = 30;

    /**
     * How the first second of this dish is told. The brand chooses (`voice.recipe_hook`): the question, the number, the time, the
     * leaflet, or the question, the number and (where the dish has a leaflet to show) the leaflet by turns. By turns is decided by the
     * dish, not by the day, so every render of it says the same. A style the dish cannot have (a leaflet it has no cover of, a time
     * it is too slow for) is the question.
     */
    public static function hookStyle(ContentItem $item, Brand $brand): string
    {
        $choice = (string) data_get($brand->voice, 'recipe_hook', self::HOOK_ALTERNATE);
        $info = RecipeInfo::tryFromItem($item);
        $minutes = $info?->minutes ?? PHP_INT_MAX;
        $hasLeaflet = $info?->leafletCover !== null;

        if ($choice === self::HOOK_ALTERNATE) {
            $turns = $hasLeaflet ? [self::HOOK_QUESTION, self::HOOK_FIGURE, self::HOOK_LEAFLET] : [self::HOOK_QUESTION, self::HOOK_FIGURE];

            return $turns[crc32($item->external_id) % count($turns)];
        }

        return match (true) {
            $choice === self::HOOK_TIME && $minutes <= self::QUICK_MINUTES => self::HOOK_TIME,
            $choice === self::HOOK_LEAFLET && $hasLeaflet => self::HOOK_LEAFLET,
            $choice === self::HOOK_FIGURE => self::HOOK_FIGURE,
            default => self::HOOK_QUESTION,
        };
    }

    /**
     * What a slide of this dish prints, in the brand's language. Null when the item does not carry a usable block.
     *
     * @return array<string, mixed>|null
     */
    public static function view(ContentItem $item, Brand $brand, string $where = CatalogCopy::WHERE_COMMENT): ?array
    {
        $info = RecipeInfo::tryFromItem($item);

        if ($info === null) {
            return null;
        }

        $language = $brand->languageCode();
        $saving = $info->shownSavingCents();
        $on = $info->ingredientsOnSale;
        $hookStyle = self::hookStyle($item, $brand);

        $tiles = [
            ['label' => Wording::get($language, 'recipe.card.on_sale_label'), 'value' => $on.' / '.$info->ingredientsTotal],
            ['label' => Wording::get($language, 'recipe.card.sum_label'), 'value' => RecipeInfo::money($info->totalCents)],
        ];

        if ($saving !== null) {
            $tiles[] = ['label' => Wording::get($language, 'recipe.card.saving_label'), 'value' => RecipeInfo::money($saving), 'accent' => true];
        }

        return [
            'hook_style' => $hookStyle,
            'question' => Wording::get($language, 'recipe.question'),
            'hook_on_sale' => self::onSaleCount($info, $language),
            'hook_saving' => $saving === null ? null : Wording::get($language, 'recipe.hook.saving', ['amount' => RecipeInfo::money($saving)]),
            'hook_time' => self::timePhrase($info, $language),
            'from_leaflet' => Wording::get($language, 'recipe.hook.from_leaflet'),
            'hook_leaflet' => Wording::get($language, 'recipe.hook.leaflet'),
            'has_leaflet' => $info->leafletCover !== null,
            'chain' => implode(' · ', $info->shops()),
            'meta' => implode(' · ', [
                Wording::get($language, 'recipe.card.minutes', ['minutes' => $info->minutes]),
                Wording::get($language, 'recipe.card.servings', ['count' => $info->servings, 'portions' => Wording::plural($language, $info->servings, 'portions')]),
                Wording::get($language, 'recipe.card.until', ['date' => self::date($info->validTo, $language)]),
            ]),
            'on_sale' => Wording::get($language, 'recipe.card.on_sale', ['on' => $on, 'total' => $info->ingredientsTotal]),
            'sum' => RecipeInfo::money($info->totalCents),
            'saving' => $saving === null ? null : RecipeInfo::money($saving),
            'tiles' => $tiles,
            // The dish's main ingredients, when the source sent them and the brand wants them on the card (voice.recipe_highlights).
            'highlights' => (bool) data_get($brand->voice, 'recipe_highlights', false) ? $info->highlights : [],
            'ai' => $info->aiImage,
            'ai_label' => Wording::get($language, 'recipe.ai_label'),
            'ai_note' => Wording::get($language, 'recipe.ai_note'),
            'cta_line' => Wording::get($language, 'recipe.cta.line', ['brand' => $brand->name]),
            'cta_get' => CatalogCopy::cta($brand),
            'link_sentence' => self::linkSentence($where, $language),
            'footer' => Wording::get($language, 'recipe.card.footer'),
        ];
    }

    /**
     * The line over the cover of a roundup of dishes: "5 jela iz akcija".
     */
    public static function coverCount(int $count, string $language): string
    {
        return Wording::get($language, 'recipe.cover.count', ['count' => $count, 'recipes' => Wording::plural($language, $count, 'recipes')]);
    }

    /**
     * "Poveznica je u komentaru.": where the link of a dish is, as the channel has it.
     */
    public static function linkSentence(string $where, string $language): string
    {
        return Wording::get($language, match ($where) {
            CatalogCopy::WHERE_BIO => 'recipe.link.bio',
            CatalogCopy::WHERE_DESCRIPTION => 'recipe.link.description',
            default => 'recipe.link.comment',
        });
    }

    /**
     * What the voice says, one text per role of the video: the hook, the card, the end.
     *
     * @return array{hook: string, card: string, closing: string}
     */
    public static function voiceLines(ContentItem $item, Brand $brand, string $where = CatalogCopy::WHERE_COMMENT): array
    {
        $info = RecipeInfo::fromItem($item);
        $language = $brand->languageCode();

        $hook = match (self::hookStyle($item, $brand)) {
            self::HOOK_FIGURE => self::onSaleCount($info, $language).'.',
            self::HOOK_TIME => self::timePhrase($info, $language).'.',
            self::HOOK_LEAFLET => Wording::get($language, 'recipe.hook.leaflet').'.',
            default => Wording::get($language, 'recipe.question'),
        };

        // Short on purpose: the title, the sum and the saving are on the screen, and a voice that reads them keeps a still card for ten
        // seconds. It says what is not obvious from the picture: how much of the dish is on sale, and where.
        $card = Wording::get($language, 'voice.recipe.on_sale', [
            'on' => $info->ingredientsOnSale,
            'total' => $info->ingredientsTotal,
            'shop' => self::shop($info, $language, spoken: true),
        ]);

        $closing = Wording::get($language, 'recipe.cta.line', ['brand' => $brand->name]).' '.self::linkSentence($where, $language);

        return ['hook' => $hook, 'card' => $card, 'closing' => $closing];
    }

    /**
     * The sentences of a caption: the lead (the dish and its numbers), the saving, where the link is and that the picture is drawn.
     *
     * @return array{lead: string, saving: string|null, link: string, ai: string|null, question: string, ask: string}
     */
    public static function captionParts(ContentItem $item, Brand $brand, string $where = CatalogCopy::WHERE_COMMENT): array
    {
        $info = RecipeInfo::fromItem($item);
        $language = $brand->languageCode();
        $saving = $info->shownSavingCents();

        return [
            'question' => Wording::get($language, 'recipe.question'),
            // A question under the post: a reason to answer in the comments without asking for it (a post that asks for a like, a share or
            // a comment is ranked lower on Facebook).
            'ask' => Wording::get($language, 'recipe.caption.ask'),
            'lead' => Wording::get($language, 'recipe.caption.body', [
                'title' => mb_rtrim(mb_trim((string) $item->title), '.!?:'),
                'on' => $info->ingredientsOnSale,
                'total' => $info->ingredientsTotal,
                'shop' => self::shop($info, $language),
                'sum' => RecipeInfo::money($info->totalCents),
            ]),
            'saving' => $saving === null ? null : Wording::get($language, 'recipe.caption.saving', ['amount' => RecipeInfo::money($saving)]),
            'link' => Wording::get($language, 'recipe.cta.line', ['brand' => $brand->name]).' '.self::linkSentence($where, $language),
            'ai' => $info->aiImage ? Wording::get($language, 'recipe.ai_note') : null,
        ];
    }

    /**
     * The first comment: where the dish and the list are.
     */
    public static function comment(string $url, Brand $brand): string
    {
        return '👉 '.Wording::get($brand, 'recipe.comment').': '.$url;
    }

    /**
     * "u trgovini Ribola" / "bei Eurospar": the shops after the voice's own word, with no case of their own to decline.
     */
    private static function shop(RecipeInfo $info, string $language, bool $spoken = false): string
    {
        $shops = $info->shops($spoken);

        if ($shops === []) {
            return '';
        }

        $last = array_pop($shops);
        $names = $shops === [] ? $last : implode(', ', $shops).' '.Wording::get($language, 'voice.and').' '.$last;

        return Wording::get($language, 'voice.in_shop', ['shop' => $names]);
    }

    private static function onSaleCount(RecipeInfo $info, string $language): string
    {
        $on = $info->ingredientsOnSale;

        return Wording::get($language, 'recipe.hook.on_sale', ['count' => $on, 'ingredients' => Wording::plural($language, $on, 'ingredients')]);
    }

    private static function timePhrase(RecipeInfo $info, string $language): string
    {
        return Wording::get($language, 'recipe.hook.time', ['minutes' => $info->minutes, 'unit' => Wording::plural($language, $info->minutes, 'minutes')]);
    }

    /**
     * Croatian spaces its dates ("17. 11."), German does not ("17.11."); the year is not needed for a week.
     */
    private static function date(string $iso, string $language): string
    {
        $date = CarbonImmutable::parse($iso);

        return Wording::language($language) === 'hr' ? $date->format('j. n.') : $date->format('j.n.');
    }

    private static function sentence(string $text): string
    {
        $text = mb_trim($text);

        return $text === '' || preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }
}
