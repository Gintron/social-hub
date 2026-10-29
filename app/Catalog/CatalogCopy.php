<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Support\Wording;

/**
 * Every word of the new-catalog video and of the post around it, written from what the item carries.
 *
 * The voice, the subtitles, the title on the screen and the caption say the same sentences: one place
 * writes them, so a change of wording cannot leave the picture saying one thing and the voice another.
 * The words about the call to action are the owner's (Marijan, 3 Oct 2026): "Poveznica do aplikacije je u
 * komentaru" — and nothing about a free trial or a saving, because neither is something the video can show.
 *
 * The words are the brand's language (Wording): a Croatian brand says "Konzumov katalog" in the form the feed gives
 * (`chain_phrase`), a German one names the chain after "von", which needs no case of its own.
 */
final class CatalogCopy
{
    /** Where the link is, as the video says it. `comment` on Facebook and Instagram; `bio` is the TikTok fallback. */
    public const WHERE_COMMENT = 'comment';

    public const WHERE_BIO = 'bio';

    /** A YouTube video's link is its description, where it is clickable: nothing is posted after the video. */
    public const WHERE_DESCRIPTION = 'description';

    /** Every place a video can say its link is. Anything else is not one. */
    public const PLACES = [self::WHERE_COMMENT, self::WHERE_BIO, self::WHERE_DESCRIPTION];

    /**
     * The four sentences, in the order they are said: the news, what to do, the call, where the link is.
     *
     * @return list<string>
     */
    public static function voiceLines(CatalogDemo $demo, string $where = self::WHERE_COMMENT, string $cta = 'Preuzmi Listo', string $language = Wording::DEFAULT): array
    {
        return [
            Wording::get($language, 'catalog.news', self::names($demo)),
            Wording::get($language, 'catalog.how'),
            self::sentence($cta),
            self::linkSentence($where, $language),
        ];
    }

    /**
     * The call to action a brand without its own says: "Preuzmi Listo", "Hol dir Listo".
     */
    public static function defaultCta(Brand $brand): string
    {
        return Wording::get($brand, 'catalog.cta', ['brand' => $brand->name]);
    }

    /**
     * The brand's call to action (`voice.cta`), or the default one in its language.
     */
    public static function cta(Brand $brand): string
    {
        $cta = mb_trim((string) data_get($brand->voice, 'cta', ''));

        return $cta === '' ? self::defaultCta($brand) : $cta;
    }

    /**
     * "Poveznica do aplikacije je u komentaru." — on the end card and in the voice.
     */
    public static function linkSentence(string $where, string $language = Wording::DEFAULT): string
    {
        return Wording::get($language, match ($where) {
            self::WHERE_BIO => 'catalog.link.bio',
            self::WHERE_DESCRIPTION => 'catalog.link.description',
            default => 'catalog.link.comment',
        });
    }

    /**
     * Where a platform's video says its link is: what config/catalog_video.php names, and a comment otherwise.
     */
    public static function where(Platform $platform): string
    {
        return self::knownPlace(config("catalog_video.link_in.{$platform->value}")) ?? self::WHERE_COMMENT;
    }

    /**
     * `$where` when it is a place a video can name, else null: the caller says what "not a place" falls back to.
     * One list for everything that reads a stored or typed place, so a place added here is not lost to the one
     * `match` that did not hear of it (a YouTube video once said "u komentaru" over a link that was in the description).
     */
    public static function knownPlace(mixed $where): ?string
    {
        return is_string($where) && in_array($where, self::PLACES, true) ? $where : null;
    }

    /**
     * The two-line title over the cover: "Izašao je novi / Konzumov katalog".
     *
     * @return array{0: string, 1: string}
     */
    public static function title(CatalogDemo $demo, string $language = Wording::DEFAULT): array
    {
        return [Wording::get($language, 'catalog.title'), Wording::get($language, 'catalog.title_chain', self::names($demo))];
    }

    /**
     * "Vrijedi od 7. 10. do 13. 10." — the dates the source gave, the year only when it is not this one.
     */
    public static function validity(CatalogDemo $demo, string $language = Wording::DEFAULT): string
    {
        $from = $demo->validFrom;
        $to = $demo->validTo;
        $sameYear = $from->year === $to->year;

        // Croatian spaces its dates and closes the year with a dot ("7. 10. 2026."); German does neither ("7.10.2026").
        [$from, $to] = Wording::language($language) === 'hr'
            ? [$from->format('j. n.').($sameYear ? '' : ' '.$from->year.'.'), $to->format('j. n. Y').'.']
            : [$from->format('j.n.').($sameYear ? '' : $from->year), $to->format('j.n.Y')];

        return Wording::get($language, 'catalog.validity', ['from' => $from, 'to' => $to]);
    }

    public static function caption(Platform $platform, ContentItem $item, Brand $brand, CatalogDemo $demo, string $where = self::WHERE_COMMENT): string
    {
        $language = $brand->languageCode();

        $sections = [
            '🛒 '.Wording::get($language, 'catalog.headline', self::names($demo)),
            self::validity($demo, $language).' '.Wording::get($language, 'catalog.how'),
            // Never a link here: on Facebook it is the first comment, on the others it is not clickable at all.
            '👇 '.self::sentence(self::cta($brand)).' '.self::linkSentence($where, $language),
        ];

        $tags = self::hashtags($item, $brand);

        if ($tags !== []) {
            $sections[] = implode(' ', array_slice($tags, 0, $platform === Platform::FacebookPage ? 3 : 5));
        }

        return mb_trim(implode("\n\n", $sections));
    }

    /**
     * The first comment: the link, tracked for this channel. Left to the caller to place; TikTok has none.
     */
    public static function comment(CatalogDemo $demo, string $url, Brand $brand): string
    {
        return '👉 '.self::cta($brand).': '.$url;
    }

    /**
     * An address as somebody types it: no scheme, no `www.`, no query and no trailing slash
     * (`https://www.uselisto.com/app?x=1` → `uselisto.com/app`). With a `$path` the site's own address of that path is
     * said instead (`uselisto.com/tiktok`), which is how a channel is told apart when nothing but the address is copied.
     */
    public static function typeable(string $url, ?string $path = null): string
    {
        $address = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', mb_trim($url));
        $address = (string) preg_replace('~^www\.~i', '', $address);
        $address = (string) preg_replace('~[?#].*$~', '', $address);
        $address = mb_rtrim($address, '/');

        $path = mb_trim((string) $path, " \t\n\r\0\x0B/");

        if ($path === '' || $address === '') {
            return $address;
        }

        return explode('/', $address, 2)[0].'/'.$path;
    }

    /**
     * @return list<string>
     */
    private static function hashtags(ContentItem $item, Brand $brand): array
    {
        $tags = [];

        foreach ([...(array) data_get($brand->voice, 'hashtags', []), ...($item->tags ?? [])] as $tag) {
            $tag = mb_strtolower(mb_ltrim((string) $tag, '#'));

            if ($tag !== '' && preg_match('/^[\p{L}\p{N}_]+$/u', $tag) === 1) {
                $tags['#'.$tag] = true;
            }
        }

        return array_keys($tags);
    }

    /**
     * What the sentences name the catalog by: the Croatian phrase ("Konzumov katalog") and the chain's own name.
     *
     * @return array{phrase: string, chain: string}
     */
    private static function names(CatalogDemo $demo): array
    {
        return ['phrase' => $demo->phrase(), 'chain' => $demo->chainName];
    }

    private static function sentence(string $text): string
    {
        $text = mb_trim($text);

        return $text === '' || preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }
}
