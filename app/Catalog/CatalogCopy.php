<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;

/**
 * Every word of the new-catalog video and of the post around it, written from what the item carries.
 *
 * The voice, the subtitles, the title on the screen and the caption say the same sentences: one place
 * writes them, so a change of wording cannot leave the picture saying one thing and the voice another.
 * The words about the call to action are the owner's (Marijan, 3 Oct 2026): "Poveznica do aplikacije je u
 * komentaru" — and nothing about a free trial or a saving, because neither is something the video can show.
 */
final class CatalogCopy
{
    /** Where the link is, as the video says it. `comment` on Facebook and Instagram; `bio` is the TikTok fallback. */
    public const WHERE_COMMENT = 'comment';

    public const WHERE_BIO = 'bio';

    /**
     * The four sentences, in the order they are said: the news, what to do, the call, where the link is.
     *
     * @return list<string>
     */
    public static function voiceLines(CatalogDemo $demo, string $where = self::WHERE_COMMENT, string $cta = 'Preuzmi Listo'): array
    {
        return [
            'Hej, izašao je novi '.$demo->phrase().'.',
            'Prelistaj ga i dodaj proizvod na svoju listu dodirom na letak.',
            self::sentence($cta),
            self::linkSentence($where),
        ];
    }

    /**
     * "Poveznica do aplikacije je u komentaru." — on the end card and in the voice.
     */
    public static function linkSentence(string $where): string
    {
        return $where === self::WHERE_BIO
            ? 'Poveznica do aplikacije je u biografiji.'
            : 'Poveznica do aplikacije je u komentaru.';
    }

    /**
     * The two-line title over the cover: "Izašao je novi / Konzumov katalog".
     *
     * @return array{0: string, 1: string}
     */
    public static function title(CatalogDemo $demo): array
    {
        return ['Izašao je novi', $demo->phrase()];
    }

    /**
     * "Vrijedi od 7. 10. do 13. 10." — the dates the source gave, the year only when it is not this one.
     */
    public static function validity(CatalogDemo $demo): string
    {
        $from = $demo->validFrom;
        $to = $demo->validTo;
        $sameYear = $from->year === $to->year;

        return sprintf(
            'Vrijedi od %s do %s',
            $from->format('j. n.').($sameYear ? '' : ' '.$from->year.'.'),
            $to->format('j. n. Y').'.',
        );
    }

    public static function caption(Platform $platform, ContentItem $item, Brand $brand, CatalogDemo $demo, string $where = self::WHERE_COMMENT): string
    {
        $cta = mb_trim((string) data_get($brand->voice, 'cta', 'Preuzmi '.$brand->name));
        $headline = '🛒 Izašao je novi '.$demo->phrase();

        $sections = [
            $headline,
            self::validity($demo).' Prelistaj ga i dodaj proizvod na svoju listu dodirom na letak.',
            // Never a link here: on Facebook it is the first comment, on the others it is not clickable at all.
            '👇 '.self::sentence($cta === '' ? 'Preuzmi '.$brand->name : $cta).' '.self::linkSentence($where),
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
        $cta = mb_trim((string) data_get($brand->voice, 'cta', ''));

        return '👉 '.($cta === '' ? 'Preuzmi '.$brand->name : $cta).': '.$url;
    }

    /**
     * An address as somebody types it: no scheme, no `www.`, no query and no trailing slash
     * (`https://www.uselisto.com/app?x=1` → `uselisto.com/app`).
     */
    public static function typeable(string $url): string
    {
        $address = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', mb_trim($url));
        $address = (string) preg_replace('~^www\.~i', '', $address);
        $address = (string) preg_replace('~[?#].*$~', '', $address);

        return mb_rtrim($address, '/');
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

    private static function sentence(string $text): string
    {
        $text = mb_trim($text);

        return $text === '' || preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }
}
