<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lengths in UTF-16 code units, which is how TikTok and YouTube count a caption or a title: an emoji
 * costs two, a Croatian letter one.
 */
final class Utf16
{
    public static function length(string $text): int
    {
        return intdiv(mb_strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), '8bit'), 2);
    }

    /**
     * The longest prefix that fits in `$max` units. It never splits a character, so an emoji is kept whole or dropped.
     */
    public static function cut(string $text, int $max): string
    {
        $units = 0;
        $out = '';

        foreach (mb_str_split($text) as $char) {
            $width = mb_ord($char) > 0xFFFF ? 2 : 1;

            if ($units + $width > $max) {
                break;
            }

            $units += $width;
            $out .= $char;
        }

        return $out;
    }
}
