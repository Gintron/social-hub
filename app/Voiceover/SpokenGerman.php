<?php

declare(strict_types=1);

namespace App\Voiceover;

/**
 * Written German in, text a speech model reads the same way every time out — the German side of SpokenCroatian.
 *
 * What a German brand's voice-over is written with: prices ("1,99 €", "9,98 €/kg"), percentages ("−30 %"), dates
 * ("25.10.2026", "7.10."), packs ("500 g", "0,5 l"), years and plain numbers. Each is spelled out here, so the voice
 * only ever receives words and never decides for itself whether "1,99 €" is a euro ninety-nine or one point ninety-nine.
 * Numbers are written the German way: a comma before the decimals, a dot between thousands. The brand's pronunciation
 * list comes first, as it does for Croatian.
 */
final class SpokenGerman
{
    private const ONES = [
        0 => 'null', 1 => 'eins', 2 => 'zwei', 3 => 'drei', 4 => 'vier', 5 => 'fünf', 6 => 'sechs', 7 => 'sieben',
        8 => 'acht', 9 => 'neun', 10 => 'zehn', 11 => 'elf', 12 => 'zwölf', 13 => 'dreizehn', 14 => 'vierzehn',
        15 => 'fünfzehn', 16 => 'sechzehn', 17 => 'siebzehn', 18 => 'achtzehn', 19 => 'neunzehn',
    ];

    private const TENS = [2 => 'zwanzig', 3 => 'dreißig', 4 => 'vierzig', 5 => 'fünfzig', 6 => 'sechzig', 7 => 'siebzig', 8 => 'achtzig', 9 => 'neunzig'];

    /** Ordinals below twenty that are not the cardinal plus "-ten" (after "am", "bis zum": the dative). */
    private const ORDINALS = [1 => 'ersten', 3 => 'dritten', 7 => 'siebten', 8 => 'achten'];

    private const MONTHS = [
        1 => 'Jänner', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    /** A unit after a number, as it is said. */
    private const UNITS = [
        'kg' => 'Kilo', 'g' => 'Gramm', 'l' => 'Liter', 'ml' => 'Milliliter', 'cl' => 'Zentiliter',
        'cm' => 'Zentimeter', 'm' => 'Meter', 'Stk' => 'Stück', 'Stück' => 'Stück',
    ];

    /** One number as it is written in German: "1.299,99", "7,5", "2026". */
    private const NUMBER = '\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:,\d+)?';

    public static function cardinal(int $number): string
    {
        if ($number < 0) {
            return 'minus '.self::cardinal(-$number);
        }

        if ($number < 1000) {
            return self::belowThousand($number);
        }

        if ($number < 1_000_000) {
            $thousands = intdiv($number, 1000);
            $rest = $number % 1000;

            return ($thousands === 1 ? 'ein' : self::compound($thousands)).'tausend'.($rest === 0 ? '' : self::compound($rest));
        }

        $millions = intdiv($number, 1_000_000);
        $rest = $number % 1_000_000;
        $head = $millions === 1 ? 'eine Million' : self::cardinal($millions).' Millionen';

        return $rest === 0 ? $head : $head.' '.self::cardinal($rest);
    }

    /**
     * "ersten", "zweiten", "einundzwanzigsten": the ordinal after "am" or "bis zum", where a date stands.
     */
    public static function ordinal(int $number): string
    {
        if ($number < 20) {
            return self::ORDINALS[$number] ?? self::ONES[$number].'ten';
        }

        return self::compound($number).'sten';
    }

    /**
     * @param  array<string, string>  $pronunciations  Written => spoken, for this brand's names.
     */
    public function speak(string $text, array $pronunciations = []): string
    {
        $text = $this->pronunciations($text, $pronunciations);
        $text = $this->strip($text);
        $text = $this->amounts($text);
        $text = $this->percentages($text);
        $text = $this->dates($text);
        $text = $this->ranges($text);
        $text = $this->units($text);
        $text = $this->years($text);
        $text = $this->numbers($text);
        $text = $this->symbols($text);

        return mb_trim((string) preg_replace('/\s{2,}/u', ' ', $text));
    }

    /**
     * The cardinal inside a longer word: "ein" in "einundzwanzig", "einhundert", "eintausend".
     */
    private static function compound(int $number): string
    {
        return $number === 1 ? 'ein' : self::cardinal($number);
    }

    private static function belowThousand(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $words = $hundreds > 0 ? ($hundreds === 1 ? 'ein' : self::ONES[$hundreds]).'hundert' : '';

        if ($rest === 0) {
            return $words === '' ? self::ONES[0] : $words;
        }

        if ($rest < 20) {
            // "hunderteins", but "eins" alone.
            return $words.self::ONES[$rest];
        }

        $ones = $rest % 10;
        $tens = self::TENS[intdiv($rest, 10)];

        return $words.($ones === 0 ? $tens : ($ones === 1 ? 'ein' : self::ONES[$ones]).'und'.$tens);
    }

    /**
     * A written number as an integer and its decimals: "1.299,99" → [1299, "99"].
     *
     * @return array{0: int, 1: string}
     */
    private static function parse(string $raw): array
    {
        [$whole, $decimals] = array_pad(explode(',', $raw, 2), 2, '');

        return [(int) str_replace('.', '', $whole), $decimals];
    }

    /**
     * "7,5" → "sieben Komma fünf": the decimals digit by digit, the way a number that is not money is read.
     */
    private static function number(string $raw): string
    {
        [$whole, $decimals] = self::parse($raw);
        $words = self::cardinal($whole);

        if ($decimals === '') {
            return $words;
        }

        return $words.' Komma '.implode(' ', array_map(fn (string $digit): string => self::ONES[(int) $digit], mb_str_split($decimals)));
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
     * "1,99 €" → "ein Euro neunundneunzig", "0,99 €" → "neunundneunzig Cent", "€ 2" → "zwei Euro", and a price per unit
     * ("9,98 €/kg") with "pro Kilo" after it.
     */
    private function amounts(string $text): string
    {
        $unit = '(?:\s?\/\s?('.implode('|', array_map(fn (string $u): string => preg_quote($u, '/'), array_keys(self::UNITS))).'))?';

        $text = (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])('.self::NUMBER.')\s?(?:€|EUR)'.$unit.'(?![\p{L}\p{N}])/u',
            fn (array $m): string => $this->money($m[1], $m[2] ?? ''),
            $text,
        );

        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])(?:€|EUR)\s?('.self::NUMBER.')'.$unit.'(?![\p{L}\p{N}])/u',
            fn (array $m): string => $this->money($m[1], $m[2] ?? ''),
            $text,
        );
    }

    private function money(string $raw, string $unit): string
    {
        [$euros, $decimals] = self::parse($raw);
        $cents = $decimals === '' ? 0 : (int) mb_str_pad(mb_substr($decimals, 0, 2), 2, '0');

        $words = match (true) {
            $euros === 0 && $cents > 0 => self::cardinal($cents).' Cent',
            $cents === 0 => ($euros === 1 ? 'ein' : self::cardinal($euros)).' Euro',
            default => ($euros === 1 ? 'ein' : self::cardinal($euros)).' Euro '.self::cardinal($cents),
        };

        return $unit === '' ? $words : $words.' pro '.self::UNITS[$unit];
    }

    /**
     * "30 %", "−30 %", "30%" → "dreißig Prozent", "minus dreißig Prozent".
     */
    private function percentages(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])([−-])?('.self::NUMBER.')\s?%/u',
            fn (array $m): string => ($m[1] !== '' ? 'minus ' : '').self::number($m[2]).' Prozent',
            $text,
        );
    }

    /**
     * "25.10.2026" → "fünfundzwanzigsten Oktober zweitausendsechsundzwanzig", "7.10." → "siebten Oktober".
     */
    private function dates(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}.])(\d{1,2})\.\s?(\d{1,2})\.(?:\s?(\d{4}))?(?![\p{N}])/u',
            function (array $m): string {
                $day = (int) $m[1];
                $month = (int) $m[2];

                if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
                    return $m[0];
                }

                $year = ($m[3] ?? '') !== '' ? ' '.self::cardinal((int) $m[3]) : '';

                return self::ordinal($day).' '.self::MONTHS[$month].$year;
            },
            $text,
        );
    }

    /**
     * "2–4" → "zwei bis vier".
     */
    private function ranges(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])('.self::NUMBER.')\s?[–-]\s?('.self::NUMBER.')(?![\p{L}\p{N}])/u',
            fn (array $m): string => self::number($m[1]).' bis '.self::number($m[2]),
            $text,
        );
    }

    /**
     * "500 g" → "fünfhundert Gramm", "1 kg" → "ein Kilo", "0,5 l" → "null Komma fünf Liter".
     */
    private function units(string $text): string
    {
        $units = implode('|', array_map(fn (string $u): string => preg_quote($u, '/'), array_keys(self::UNITS)));

        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])('.self::NUMBER.')\s?('.$units.')\.?(?![\p{L}\p{N}])/u',
            fn (array $m): string => ($m[1] === '1' ? 'ein' : self::number($m[1])).' '.self::UNITS[$m[2]],
            $text,
        );
    }

    /**
     * A year on its own: "2026" → "zweitausendsechsundzwanzig" (what cardinal says too), kept apart for the years
     * 1100–1999, which German says in hundreds ("neunzehnhundertneunundneunzig").
     */
    private function years(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N},.])(1[1-9])(\d\d)(?![\p{L}\p{N},.])/u',
            fn (array $m): string => self::cardinal((int) $m[1]).'hundert'.((int) $m[2] === 0 ? '' : self::cardinal((int) $m[2])),
            $text,
        );
    }

    private function numbers(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{N}])('.self::NUMBER.')(?![\p{L}\p{N}])/u',
            fn (array $m): string => self::number($m[1]),
            $text,
        );
    }

    private function symbols(string $text): string
    {
        return str_replace(['&', ' + ', ' = ', '→', '…', '/'], [' und ', ' plus ', ' gleich ', ', ', '...', ' pro '], $text);
    }
}
