<?php

declare(strict_types=1);

namespace App\Voiceover;

/**
 * Written English in, text a speech model reads the same way every time out — the English side of SpokenCroatian.
 *
 * A model left to read "$1,299.99", "20%" or "2026" decides for itself how, and not always the same way; so amounts,
 * percentages, times, ordinals, years and plain numbers are spelled out here and the voice only ever receives words.
 * The rules cover what a short's script is written with (money, percentages, a time of day, "10k", "24/7"), not English
 * in general. The brand's pronunciation list comes first, as it does for Croatian.
 */
final class SpokenEnglish
{
    private const ONES = [
        0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven',
        8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen',
        15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen',
    ];

    private const TENS = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];

    private const SCALES = [1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'];

    /** The last word of a cardinal and the ordinal it becomes. */
    private const ORDINAL_WORDS = [
        'one' => 'first', 'two' => 'second', 'three' => 'third', 'five' => 'fifth', 'eight' => 'eighth',
        'nine' => 'ninth', 'twelve' => 'twelfth',
    ];

    /** Symbol or code => [one, many, one cent, many cents]. */
    private const CURRENCIES = [
        '$' => ['dollar', 'dollars', 'cent', 'cents'],
        'USD' => ['dollar', 'dollars', 'cent', 'cents'],
        '€' => ['euro', 'euros', 'cent', 'cents'],
        'EUR' => ['euro', 'euros', 'cent', 'cents'],
        '£' => ['pound', 'pounds', 'penny', 'pence'],
        'GBP' => ['pound', 'pounds', 'penny', 'pence'],
    ];

    private const SUFFIXES = ['k' => 1_000, 'K' => 1_000, 'm' => 1_000_000, 'M' => 1_000_000, 'bn' => 1_000_000_000, 'B' => 1_000_000_000];

    /** One number as it is written in English: "1,299.99", "7.5", "2026". */
    private const NUMBER = '\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?';

    public static function cardinal(int $number): string
    {
        if ($number < 0) {
            return 'minus '.self::cardinal(-$number);
        }

        if ($number < 1000) {
            return self::belowThousand($number);
        }

        $parts = [];

        foreach (self::SCALES as $scale => $word) {
            if ($number >= $scale) {
                $parts[] = self::cardinal(intdiv($number, $scale)).' '.$word;
                $number %= $scale;
            }
        }

        if ($number > 0) {
            $parts[] = self::belowThousand($number);
        }

        return implode(' ', $parts);
    }

    public static function ordinal(int $number): string
    {
        $words = self::cardinal($number);
        $last = (string) preg_replace('/^.*[\s-]/u', '', $words);
        $ordinal = self::ORDINAL_WORDS[$last] ?? (str_ends_with($last, 'y') ? mb_substr($last, 0, -1).'ieth' : $last.'th');

        return mb_substr($words, 0, mb_strlen($words) - mb_strlen($last)).$ordinal;
    }

    /**
     * "2026" → "twenty twenty-six", "2005" → "two thousand five", "1990" → "nineteen ninety".
     */
    public static function year(int $year): string
    {
        if ($year >= 2000 && $year < 2010) {
            return self::cardinal($year);
        }

        $high = intdiv($year, 100);
        $low = $year % 100;

        return self::cardinal($high).' '.match (true) {
            $low === 0 => 'hundred',
            $low < 10 => 'oh '.self::cardinal($low),
            default => self::cardinal($low),
        };
    }

    /**
     * @param  array<string, string>  $pronunciations  How this brand spells names for the voice: written => spoken.
     */
    public function speak(string $text, array $pronunciations = []): string
    {
        $text = $this->pronunciations($text, $pronunciations);
        $text = $this->strip($text);
        $text = $this->amounts($text);
        $text = $this->percentages($text);
        $text = $this->times($text);
        $text = $this->ordinals($text);
        $text = $this->ranges($text);
        $text = $this->suffixed($text);
        $text = $this->years($text);
        $text = $this->numbers($text);
        $text = $this->symbols($text);

        return mb_trim((string) preg_replace('/\s{2,}/u', ' ', $text));
    }

    private static function belowThousand(int $number): string
    {
        $parts = [];

        if ($number >= 100) {
            $parts[] = self::ONES[intdiv($number, 100)].' hundred';
            $number %= 100;
        }

        if ($number >= 20) {
            $parts[] = self::TENS[intdiv($number, 10)].($number % 10 > 0 ? '-'.self::ONES[$number % 10] : '');
        } elseif ($number > 0 || $parts === []) {
            $parts[] = self::ONES[$number];
        }

        return implode(' ', $parts);
    }

    /**
     * "1,299.99" → [1299, "99"].
     *
     * @return array{0: int, 1: string}
     */
    private static function parse(string $raw): array
    {
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '', $raw), 2), 2, '');

        return [(int) $whole, $fraction];
    }

    /**
     * "3.5" → "three point five", "0.05" → "zero point zero five".
     */
    private static function decimal(string $raw): string
    {
        [$whole, $fraction] = self::parse($raw);
        $fraction = mb_rtrim($fraction, '0');

        if ($fraction === '') {
            return self::cardinal($whole);
        }

        $digits = array_map(fn (string $digit): string => self::ONES[(int) $digit], mb_str_split($fraction));

        return self::cardinal($whole).' point '.implode(' ', $digits);
    }

    /**
     * @param  array<string, string>  $pronunciations
     */
    private function pronunciations(string $text, array $pronunciations): string
    {
        foreach ($pronunciations as $written => $spoken) {
            $written = mb_trim((string) $written);

            if ($written !== '') {
                $text = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($written, '/').'(?![\p{L}\p{N}])/iu', (string) $spoken, $text);
            }
        }

        return $text;
    }

    /**
     * Emojis, hashtag signs and markdown are not read out.
     */
    private function strip(string $text): string
    {
        $text = (string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text);
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])#(?=\p{L})/u', '', $text);

        return str_replace(['*', '_', '`'], '', $text);
    }

    /**
     * "$5", "$1,299.99", "€20", "5 EUR", "£3.50", "$10k".
     */
    private function amounts(string $text): string
    {
        $symbols = '\$|€|£';
        $codes = 'USD|EUR|GBP';
        $suffix = '(k|K|m|M|bn|B)?';

        $text = (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])('.$symbols.')\s?('.self::NUMBER.')'.$suffix.'(?![\p{L}\p{N}])/u',
            fn (array $m): string => $this->money($m[1], $m[2], $m[3] ?? ''),
            $text,
        );

        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}.,])('.self::NUMBER.')'.$suffix.'\s?('.$symbols.'|'.$codes.')(?![\p{L}])/u',
            fn (array $m): string => $this->money($m[3], $m[1], $m[2]),
            $text,
        );
    }

    private function money(string $currency, string $raw, string $suffix): string
    {
        [$one, $many, $cent, $cents] = self::CURRENCIES[$currency];

        if ($suffix !== '') {
            return $this->scaled($raw, $suffix).' '.$many;
        }

        [$whole, $fraction] = self::parse($raw);
        $hundredths = $fraction === '' ? 0 : (int) mb_substr(mb_str_pad($fraction, 2, '0'), 0, 2);

        $words = self::cardinal($whole).' '.($whole === 1 ? $one : $many);

        if ($hundredths > 0) {
            $words = ($whole === 0 ? '' : $words.' and ').self::cardinal($hundredths).' '.($hundredths === 1 ? $cent : $cents);
        }

        return $words;
    }

    private function percentages(string $text): string
    {
        return (string) preg_replace_callback(
            '/('.self::NUMBER.')\s?%/u',
            fn (array $m): string => self::decimal($m[1]).' percent',
            $text,
        );
    }

    /**
     * "7:30" → "seven thirty", "7:05" → "seven oh five", "7:00" → "seven o'clock", "7pm" → "seven p m".
     */
    private function times(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/(?<![\p{N}:])([01]?\d|2[0-3]):([0-5]\d)(?:\s?([ap])(?:\.m\.|m))?(?![\p{N}])/iu',
            function (array $m): string {
                $hour = self::cardinal((int) $m[1]);
                $minutes = (int) $m[2];
                $spoken = $hour.' '.match (true) {
                    $minutes === 0 => filled($m[3] ?? null) ? '' : "o'clock",
                    $minutes < 10 => 'oh '.self::cardinal($minutes),
                    default => self::cardinal($minutes),
                };

                return mb_trim($spoken).(filled($m[3] ?? null) ? ' '.mb_strtolower($m[3]).' m' : '');
            },
            $text,
        );

        return (string) preg_replace_callback(
            '/(?<![\p{N}])(1[0-2]|0?[1-9])\s?([ap])(?:\.m\.|m)(?![\p{L}])/iu',
            fn (array $m): string => self::cardinal((int) $m[1]).' '.mb_strtolower($m[2]).' m',
            $text,
        );
    }

    private function ordinals(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{N}])(\d+)(st|nd|rd|th)(?![\p{L}])/iu',
            fn (array $m): string => self::ordinal((int) $m[1]),
            $text,
        );
    }

    /**
     * "5-10" → "five to ten", "24/7" → "twenty-four seven".
     */
    private function ranges(string $text): string
    {
        $text = str_replace('24/7', 'twenty-four seven', $text);

        return (string) preg_replace_callback(
            '/(?<![\p{N}.,])('.self::NUMBER.')\s?[-–]\s?('.self::NUMBER.')(?![\p{N}])/u',
            fn (array $m): string => self::decimal($m[1]).' to '.self::decimal($m[2]),
            $text,
        );
    }

    /**
     * "10k" → "ten thousand", "2.5M" → "two point five million".
     */
    private function suffixed(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}.,])('.self::NUMBER.')(k|K|M|bn)(?![\p{L}\p{N}])/u',
            fn (array $m): string => $this->scaled($m[1], $m[2]),
            $text,
        );
    }

    private function scaled(string $raw, string $suffix): string
    {
        $word = self::SCALES[self::SUFFIXES[$suffix]];

        return self::decimal($raw).' '.$word;
    }

    /**
     * A four-digit number between 1100 and 2099 that stands alone is a year; "the 1990s" is "the nineteen nineties".
     */
    private function years(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{N}.,$€£])(1[1-9]\d\d|20\d\d)(s|\'s)?(?![\p{N}.,]\d)/u',
            function (array $m): string {
                $spoken = self::year((int) $m[1]);

                if (($m[2] ?? '') === '') {
                    return $spoken;
                }

                return str_ends_with($spoken, 'y') ? mb_substr($spoken, 0, -1).'ies' : $spoken.'s';
            },
            $text,
        );
    }

    private function numbers(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{L}])('.self::NUMBER.')(?![\p{N}])/u',
            fn (array $m): string => self::decimal($m[1]),
            $text,
        );
    }

    private function symbols(string $text): string
    {
        return str_replace(['&', ' + ', ' = ', '→', '…'], [' and ', ' plus ', ' equals ', ', ', '...'], $text);
    }
}
