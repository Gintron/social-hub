<?php

declare(strict_types=1);

namespace App\Voiceover;

use Carbon\CarbonInterface;

/**
 * Written Croatian in, text a speech model reads correctly out.
 *
 * A price is the one thing a voice-over must never get wrong, and a model left to read "1,49 €" or
 * "7,00 – 8,00 €/H" on its own decides for itself whether that is "jedan zarez četrdeset devet",
 * "jedan euro" or something else, differently from one day to the next. So every number, amount,
 * percentage, unit and date is spelled out here, in the right case, before the text leaves the
 * hub; the model only ever receives words.
 *
 * The rules are the ones the feeds actually produce (retail prices with a unit, pay ranges, pack
 * sizes, "d.o.o.", "Konobar/ica"), not Croatian in general. What it cannot know — a brand's own
 * spelling of a name — comes from the brand's pronunciation list.
 */
final class SpokenCroatian
{
    private const ONES = [
        0 => 'nula', 1 => 'jedan', 2 => 'dva', 3 => 'tri', 4 => 'četiri', 5 => 'pet', 6 => 'šest', 7 => 'sedam',
        8 => 'osam', 9 => 'devet', 10 => 'deset', 11 => 'jedanaest', 12 => 'dvanaest', 13 => 'trinaest',
        14 => 'četrnaest', 15 => 'petnaest', 16 => 'šesnaest', 17 => 'sedamnaest', 18 => 'osamnaest', 19 => 'devetnaest',
    ];

    private const TENS = [
        2 => 'dvadeset', 3 => 'trideset', 4 => 'četrdeset', 5 => 'pedeset',
        6 => 'šezdeset', 7 => 'sedamdeset', 8 => 'osamdeset', 9 => 'devedeset',
    ];

    private const HUNDREDS = [
        1 => 'sto', 2 => 'dvjesto', 3 => 'tristo', 4 => 'četiristo', 5 => 'petsto',
        6 => 'šesto', 7 => 'sedamsto', 8 => 'osamsto', 9 => 'devetsto',
    ];

    /** "do 30. rujna": the genitive an ordinal date takes after do, od and iza. */
    private const DAYS_GENITIVE = [
        1 => 'prvog', 2 => 'drugog', 3 => 'trećeg', 4 => 'četvrtog', 5 => 'petog', 6 => 'šestog', 7 => 'sedmog',
        8 => 'osmog', 9 => 'devetog', 10 => 'desetog', 11 => 'jedanaestog', 12 => 'dvanaestog', 13 => 'trinaestog',
        14 => 'četrnaestog', 15 => 'petnaestog', 16 => 'šesnaestog', 17 => 'sedamnaestog', 18 => 'osamnaestog',
        19 => 'devetnaestog', 20 => 'dvadesetog', 21 => 'dvadeset prvog', 22 => 'dvadeset drugog',
        23 => 'dvadeset trećeg', 24 => 'dvadeset četvrtog', 25 => 'dvadeset petog', 26 => 'dvadeset šestog',
        27 => 'dvadeset sedmog', 28 => 'dvadeset osmog', 29 => 'dvadeset devetog', 30 => 'tridesetog',
        31 => 'trideset prvog',
    ];

    private const MONTHS_GENITIVE = [
        1 => 'siječnja', 2 => 'veljače', 3 => 'ožujka', 4 => 'travnja', 5 => 'svibnja', 6 => 'lipnja',
        7 => 'srpnja', 8 => 'kolovoza', 9 => 'rujna', 10 => 'listopada', 11 => 'studenoga', 12 => 'prosinca',
    ];

    /** As a month is written in prose after its day: "3. listopada". */
    private const MONTH_NAMES = 'siječnja|veljače|ožujka|travnja|svibnja|lipnja|srpnja|kolovoza|rujna|listopada|studenoga|studenog|prosinca';

    /**
     * What follows a number: the noun for one (1, 21, 101), for 2–4 (22, 103) and for the rest, plus the
     * form after a decimal ("1,5 kilograma") and the locative after "po" ("po kilogramu").
     */
    private const UNITS = [
        'kg' => ['m', 'kilogram', 'kilograma', 'kilograma', 'kilograma', 'kilogramu'],
        'g' => ['m', 'gram', 'grama', 'grama', 'grama', 'gramu'],
        'dag' => ['m', 'dekagram', 'dekagrama', 'dekagrama', 'dekagrama', 'dekagramu'],
        'l' => ['f', 'litra', 'litre', 'litara', 'litre', 'litri'],
        'dl' => ['m', 'decilitar', 'decilitra', 'decilitara', 'decilitra', 'decilitru'],
        'ml' => ['m', 'mililitar', 'mililitra', 'mililitara', 'mililitra', 'mililitru'],
        'cm' => ['m', 'centimetar', 'centimetra', 'centimetara', 'centimetra', 'centimetru'],
        'mm' => ['m', 'milimetar', 'milimetra', 'milimetara', 'milimetra', 'milimetru'],
        'm' => ['m', 'metar', 'metra', 'metara', 'metra', 'metru'],
        'kom' => ['m', 'komad', 'komada', 'komada', 'komada', 'komadu'],
        'h' => ['m', 'sat', 'sata', 'sati', 'sata', 'satu'],
    ];

    /** Units that only ever follow "€/" — pay per hour, a price per person. */
    private const PER_ONLY = [
        'sat' => 'satu', 'dan' => 'danu', 'mj' => 'mjesecu', 'mjesec' => 'mjesecu', 'god' => 'godini',
        'pak' => 'pakiranju', 'os' => 'osobi', 'osobi' => 'osobi', 'noć' => 'noći', 'm2' => 'kvadratnom metru',
        'm²' => 'kvadratnom metru',
    ];

    /** How Croatian reads these when they stand alone. */
    private const ACRONYMS = [
        'PDV' => 'pe de ve', 'TV' => 'te ve', 'USB' => 'u es be', 'SMS' => 'es em es', 'WC' => 've ce',
        'GPS' => 'ge pe es', 'PC' => 'pe ce', 'CD' => 'ce de', 'DVD' => 'de ve de', 'OIB' => 'o i be',
        'HZZO' => 'ha ze ze o', 'HZMO' => 'ha ze em o', 'HNB' => 'ha en be', 'IBAN' => 'iban', 'LED' => 'led',
        'LCD' => 'el ce de', 'BIO' => 'bio', 'ATM' => 'a te em', 'IT' => 'i te', 'EU' => 'e u', 'SAD' => 'sad',
    ];

    private const TLDS = ['hr' => 'ha er', 'com' => 'kom', 'eu' => 'e u', 'net' => 'net', 'org' => 'org', 'info' => 'info'];

    /** One number as feeds write it: "1.299,00" (thousands dot, decimal comma), "7.50", "2026". */
    private const NUMBER = '\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:[.,]\d+)?';

    private const UNIT_ALTERNATIVES = 'kg|dag|dl|ml|cm|mm|kom|g|l|m|h';

    /**
     * 0–999 999 999 in words. The gender is that of the noun that follows — "jedan euro", "jedna litra",
     * "dvije litre" — and only touches 1 and 2, including inside 21, 22, 101…
     */
    public static function cardinal(int $number, string $gender = 'm'): string
    {
        if ($number < 0) {
            return 'minus '.self::cardinal(-$number, $gender);
        }

        if ($number === 0) {
            return self::ONES[0];
        }

        $parts = [];
        $millions = intdiv($number, 1_000_000);
        $thousands = intdiv($number % 1_000_000, 1000);
        $rest = $number % 1000;

        if ($millions > 0) {
            $parts[] = $millions === 1
                ? 'jedan milijun'
                : self::belowThousand($millions, 'm').' '.(self::pluralClass($millions) === 'one' ? 'milijun' : 'milijuna');
        }

        if ($thousands > 0) {
            $parts[] = $thousands === 1
                ? 'tisuću'
                : self::belowThousand($thousands, 'f').' '.match (self::pluralClass($thousands)) {
                    'one' => 'tisuća',
                    'few' => 'tisuće',
                    default => 'tisuća',
                };
        }

        if ($rest > 0) {
            $parts[] = self::belowThousand($rest, $gender);
        }

        return implode(' ', $parts);
    }

    /**
     * An amount in euros as it is said: "jedan euro i četrdeset devet centi".
     */
    public static function money(int $euros, int $cents = 0): string
    {
        $parts = [];

        if ($euros > 0 || $cents === 0) {
            $parts[] = self::cardinal($euros).' '.(self::pluralClass($euros) === 'one' ? 'euro' : 'eura');
        }

        if ($cents > 0) {
            $parts[] = self::cardinal($cents).' '.match (self::pluralClass($cents)) {
                'one' => 'cent',
                'few' => 'centa',
                default => 'centi',
            };
        }

        return implode(' i ', $parts);
    }

    /**
     * "tridesetog rujna": the form that follows "do" and "od".
     */
    public static function date(CarbonInterface $date): string
    {
        return self::DAYS_GENITIVE[$date->day].' '.self::MONTHS_GENITIVE[$date->month];
    }

    /**
     * 'one' for 1, 21, 101 (not 11); 'few' for 2–4, 22–24 (not 12–14); 'many' otherwise. Which noun form a
     * numeral takes.
     */
    public static function pluralClass(int $number): string
    {
        $mod10 = $number % 10;
        $mod100 = $number % 100;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'one',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'few',
            default => 'many',
        };
    }

    /**
     * The words a pronunciation list makes the voice say (its `say` side), in lower case. What a pronunciation gives is
     * what is heard, so no IPA is put on those words (Phonetizer::prepare), even where the brand also has an IPA for
     * the same word. The written side is gone from the line once `speak` has run, so it needs no entry here.
     *
     * @param  array<string, string>  $pronunciations  Written => spoken, as VoiceoverSettings keeps them.
     * @return list<string>
     */
    public static function pronounced(array $pronunciations): array
    {
        $words = [];

        foreach ($pronunciations as $spoken) {
            foreach (Ipa::words(Ipa::normalize((string) $spoken)) as $word) {
                $words[mb_strtolower($word['text'])] = true;
            }
        }

        return array_keys($words);
    }

    /**
     * Written Croatian in, what the voice reads out: the pronunciation list first, then the rules for numbers, amounts,
     * dates and the rest. The IPA of the brand's words (Phonetizer) comes after this, on the text this returns.
     *
     * @param  array<string, string>  $pronunciations  How this brand spells names for the voice: written => spoken.
     */
    public function speak(string $text, array $pronunciations = []): string
    {
        $text = $this->pronunciations($text, $pronunciations);
        $text = $this->strip($text);
        $text = $this->abbreviations($text);
        $text = $this->genderedTitles($text);
        $text = $this->domains($text);
        $text = $this->dates($text);
        $text = $this->times($text);
        $text = $this->ranges($text);
        $text = $this->amounts($text);
        $text = $this->percentages($text);
        $text = $this->multipliers($text);
        $text = $this->quantities($text);
        $text = $this->numbers($text);
        $text = $this->shouting($text);
        $text = $this->symbols($text);

        return $this->tidy($text);
    }

    private static function belowThousand(int $number, string $gender): string
    {
        $parts = [];
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;

        if ($hundreds > 0) {
            $parts[] = self::HUNDREDS[$hundreds];
        }

        if ($rest >= 20) {
            $parts[] = self::TENS[intdiv($rest, 10)];
            $rest %= 10;
        }

        if ($rest > 0) {
            $parts[] = match (true) {
                $rest === 1 => match ($gender) {
                    'f' => 'jedna',
                    'n' => 'jedno',
                    default => 'jedan',
                },
                $rest === 2 => $gender === 'f' ? 'dvije' : 'dva',
                default => self::ONES[$rest],
            };
        }

        return implode(' ', $parts);
    }

    /**
     * Whole part and fraction digits of a written number; "1.299,00" and "1299.00" are both 1299 and "00".
     *
     * @return array{0: int, 1: string}
     */
    private static function parseNumber(string $raw): array
    {
        $raw = mb_trim($raw);

        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,(\d+))?$/', $raw, $matches) === 1) {
            return [(int) str_replace('.', '', explode(',', $raw)[0]), $matches[1] ?? ''];
        }

        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $raw), 2), 2, '');

        return [(int) $whole, $fraction];
    }

    private static function hasFraction(string $fraction): bool
    {
        return mb_rtrim($fraction, '0') !== '';
    }

    /**
     * An amount of euros: 7 → 7 euros, 7.5 → 7 euros 50 cents.
     */
    private static function amountWords(int $whole, string $fraction): string
    {
        $cents = self::hasFraction($fraction) ? (int) mb_substr(mb_str_pad($fraction, 2, '0'), 0, 2) : 0;

        return self::money($whole, $cents);
    }

    /**
     * "3,5" → "tri zarez pet"; "0,05" → "nula zarez nula pet".
     */
    private static function decimalWords(int $whole, string $fraction): string
    {
        $fraction = mb_rtrim($fraction, '0');

        if ($fraction === '') {
            return self::cardinal($whole);
        }

        $zeros = mb_strlen($fraction) - mb_strlen(mb_ltrim($fraction, '0'));
        $digits = mb_ltrim($fraction, '0');

        return self::cardinal($whole).' zarez '
            .str_repeat(self::ONES[0].' ', $zeros)
            .($digits === '' ? '' : self::cardinal((int) $digits));
    }

    /**
     * @param  array<string, string>  $pronunciations
     */
    private function pronunciations(string $text, array $pronunciations): string
    {
        foreach ($pronunciations as $written => $spoken) {
            $written = mb_trim((string) $written);

            if ($written === '') {
                continue;
            }

            $text = preg_replace_callback(
                '/(?<![\p{L}\p{N}])'.preg_quote($written, '/').'(?![\p{L}\p{N}])/iu',
                fn (): string => (string) $spoken,
                $text,
            ) ?? $text;
        }

        return $text;
    }

    /**
     * Emoji and pictographs are for the eye; read aloud they are noise or a pause in the wrong place.
     */
    private function strip(string $text): string
    {
        $text = preg_replace('/[\p{So}\p{Cs}\x{FE0F}\x{200D}\x{20E3}®™©]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\bhttps?:\/\/(?:www\.)?/i', '', $text) ?? $text;

        return preg_replace('/\bwww\./i', '', $text) ?? $text;
    }

    private function abbreviations(string $text): string
    {
        $replacements = [
            '/\b(?:j\.\s?)?d\.\s?o\.\s?o\.?/iu' => '',
            '/\bd\.\s?d\.(?=\s|$|,)/iu' => '',
            '/\bm\.\s?m\./iu' => 'mliječne masti',
            '/\bnpr\./iu' => 'na primjer',
            '/\btj\./iu' => 'to jest',
            '/\bitd\./iu' => 'i tako dalje',
            '/\bcca\.?(?=\s)/iu' => 'otprilike',
            '/\bca\.(?=\s)/iu' => 'otprilike',
            '/\bul\.(?=\s)/iu' => 'ulica',
            '/\bbr\.(?=\s)/iu' => 'broj',
            '/\btel\.(?=\s)/iu' => 'telefon',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    /**
     * Job ads name both genders: "Konobar/ica", "Radnik/ca (m/ž)". Said the way a person would, as both.
     */
    private function genderedTitles(string $text): string
    {
        $text = preg_replace('/\(\s*m\s*[\/\-]\s*[žz]\s*(?:[\/\-]\s*d\s*)?\)/iu', ' ', $text) ?? $text;
        $text = preg_replace('/(?<![\p{L}\p{N}])m\s*\/\s*ž(?:\s*\/\s*d)?(?![\p{L}\p{N}])/iu', ' ', $text) ?? $text;

        return preg_replace_callback(
            '/(?<![\p{L}\p{N}])(\p{L}+)\/(ica|ka|ca)(?![\p{L}\p{N}])/u',
            function (array $match): string {
                [, $base, $suffix] = $match;
                $feminine = $suffix === 'ca' && str_ends_with($base, 'k')
                    ? mb_substr($base, 0, -1).'ca'
                    : $base.$suffix;

                return $base.' ili '.mb_strtolower($feminine);
            },
            $text,
        ) ?? $text;
    }

    /**
     * "uselisto.com" → "uselisto točka kom". Anything else with a dot in it is left for the model.
     */
    private function domains(string $text): string
    {
        return preg_replace_callback(
            '/(?<![\p{L}\p{N}@.])((?:[\p{L}\p{N}]+-)*[\p{L}\p{N}]+)\.(hr|com|eu|net|org|info)(?![\p{L}\p{N}])/iu',
            fn (array $match): string => str_replace('-', ' ', $match[1]).' točka '.self::TLDS[mb_strtolower($match[2])],
            $text,
        ) ?? $text;
    }

    /**
     * Dates as feeds and flyers write them: "30.09.2026." → "tridesetog rujna", "3. listopada" → "trećeg
     * listopada", and the ranges "3.-9. listopada" and "15.10.-21.10." → "od trećeg do devetog listopada".
     * The year is left out: it is never what a listing turns on.
     */
    private function dates(string $text): string
    {
        $months = self::MONTH_NAMES;
        $genitive = fn (int $day): ?string => self::DAYS_GENITIVE[$day] ?? null;

        // Days of one named month: "3. do 9. listopada", "3.-9. listopada".
        $text = preg_replace_callback(
            '/(?<![\d.,])(\d{1,2})\.\s*(?:do|[–—-])\s*(\d{1,2})\.\s*('.$months.')(?![\p{L}])/iu',
            fn (array $match): string => ($first = $genitive((int) $match[1])) !== null && ($last = $genitive((int) $match[2])) !== null
                ? " od {$first} do {$last} {$match[3]} "
                : $match[0],
            $text,
        ) ?? $text;

        // A range in numbers: "15.10.-21.10.2026.", "15.-21.10.".
        $text = preg_replace_callback(
            '/(?<![\d.,])(\d{1,2})\.(?:\s?(\d{1,2})\.)?\s*[–—-]\s*(\d{1,2})\.\s?(\d{1,2})\.(?:\s?\d{4}\.?)?(?!\d)/u',
            function (array $match) use ($genitive): string {
                $firstMonth = ($match[2] ?? '') !== '' ? (int) $match[2] : (int) $match[4];
                $lastMonth = (int) $match[4];
                $first = $genitive((int) $match[1]);
                $last = $genitive((int) $match[3]);

                if ($first === null || $last === null || $firstMonth < 1 || $firstMonth > 12 || $lastMonth < 1 || $lastMonth > 12) {
                    return $match[0];
                }

                return ' od '.$first.($firstMonth !== $lastMonth ? ' '.self::MONTHS_GENITIVE[$firstMonth] : '')
                    .' do '.$last.' '.self::MONTHS_GENITIVE[$lastMonth].' ';
            },
            $text,
        ) ?? $text;

        // One day with its month named: "3. listopada".
        $text = preg_replace_callback(
            '/(?<![\d.,])(\d{1,2})\.\s+('.$months.')(?![\p{L}])/iu',
            fn (array $match): string => ($day = $genitive((int) $match[1])) !== null ? " {$day} {$match[2]} " : $match[0],
            $text,
        ) ?? $text;

        // One date in numbers: "30.09.2026.", "15.9.".
        $text = preg_replace_callback(
            '/(?<![\d.,])(\d{1,2})\.\s?(\d{1,2})\.(?:\s?\d{4}\.?)?(?!\d)/u',
            function (array $match): string {
                $day = (int) $match[1];
                $month = (int) $match[2];

                if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
                    return $match[0];
                }

                return ' '.self::DAYS_GENITIVE[$day].' '.self::MONTHS_GENITIVE[$month].' ';
            },
            $text,
        ) ?? $text;

        // "od" already in the text, and added by a range.
        return preg_replace('/\b(od)\s+od\b/iu', '$1', $text) ?? $text;
    }

    private function times(string $text): string
    {
        return preg_replace_callback(
            '/(?<![\d:.,])([01]?\d|2[0-3]):([0-5]\d)(?![\d:])/u',
            fn (array $match): string => (int) $match[2] === 0
                ? self::cardinal((int) $match[1]).' sati'
                : self::cardinal((int) $match[1]).' i '.self::cardinal((int) $match[2]),
            $text,
        ) ?? $text;
    }

    /**
     * "7,00 – 8,00 €/H" → "od sedam do osam eura po satu".
     */
    private function ranges(string $text): string
    {
        $pattern = '/(?<![\d.,])(?<from>'.self::NUMBER.')\s*[–—-]\s*(?<to>'.self::NUMBER.')\s*'
            .'(?<unit>€|%|(?:eur|'.self::UNIT_ALTERNATIVES.')(?![\p{L}\p{N}]))(?:\s*\/\s*(?<per>[\p{L}²\d]+))?/iu';

        return preg_replace_callback($pattern, function (array $match): string {
            [$fromWhole, $fromFraction] = self::parseNumber($match['from']);
            [$toWhole, $toFraction] = self::parseNumber($match['to']);
            $unit = mb_strtolower($match['unit']);

            if ($unit === '€' || $unit === 'eur') {
                $wholeOnly = ! self::hasFraction($fromFraction) && ! self::hasFraction($toFraction);
                $from = $wholeOnly ? self::cardinal($fromWhole) : self::amountWords($fromWhole, $fromFraction);

                return ' od '.$from.' do '.self::amountWords($toWhole, $toFraction).$this->per($match['per'] ?? '').' ';
            }

            if ($unit === '%') {
                return ' od '.self::decimalWords($fromWhole, $fromFraction).' do '.self::decimalWords($toWhole, $toFraction).' posto ';
            }

            $noun = self::UNITS[$unit] ?? null;

            if ($noun === null) {
                return $match[0];
            }

            return ' od '.self::cardinal($fromWhole, $noun[0]).' do '.$this->quantity($toWhole, $toFraction, $unit).' ';
        }, $text) ?? $text;
    }

    private function amounts(string $text): string
    {
        $amount = fn (array $match): string => ' '.self::amountWords(...self::parseNumber($match['amount'])).$this->per($match['per'] ?? '').' ';

        $text = preg_replace_callback(
            '/(?<![\d.,])(?<amount>'.self::NUMBER.')\s*(?:€|eur(?![\p{L}])|eura(?![\p{L}])|euro(?![\p{L}]))(?:\s*\/\s*(?<per>[\p{L}²\d]+))?/iu',
            $amount,
            $text,
        ) ?? $text;

        return preg_replace_callback(
            '/€\s*(?<amount>'.self::NUMBER.')(?![\d])/u',
            $amount,
            $text,
        ) ?? $text;
    }

    /**
     * "po satu", "po kilogramu": what a price is per, in the locative.
     */
    private function per(string $unit): string
    {
        $unit = mb_strtolower(mb_trim($unit));

        if ($unit === '') {
            return '';
        }

        $spoken = self::UNITS[$unit][5] ?? self::PER_ONLY[$unit] ?? $unit;

        return ' po '.$spoken;
    }

    private function percentages(string $text): string
    {
        $text = preg_replace('/(?<![\p{L}\p{N}])[−–-](?=\s?\d+(?:[.,]\d+)?\s*%)/u', 'minus ', $text) ?? $text;

        return preg_replace_callback(
            '/(?<![\d.,])(?<amount>'.self::NUMBER.')\s*%/u',
            fn (array $match): string => ' '.self::decimalWords(...self::parseNumber($match['amount'])).' posto ',
            $text,
        ) ?? $text;
    }

    /**
     * "6x0,33 l" is six times, said before the unit pass reads the 0,33.
     */
    private function multipliers(string $text): string
    {
        return preg_replace_callback(
            '/(?<![\d.,])(\d{1,3})\s*[x×]\s*(?=\d)/iu',
            fn (array $match): string => self::cardinal((int) $match[1]).' puta ',
            $text,
        ) ?? $text;
    }

    /**
     * "500 g" → "petsto grama", "1 kg" → "jedan kilogram", "2 l" → "dvije litre".
     */
    private function quantities(string $text): string
    {
        return preg_replace_callback(
            '/(?<![\d.,])(?<amount>'.self::NUMBER.')\s*(?<unit>'.self::UNIT_ALTERNATIVES.')(?![\p{L}\p{N}])(?:\.(?=\s|$))?/iu',
            function (array $match): string {
                $unit = mb_strtolower($match['unit']);
                [$whole, $fraction] = self::parseNumber($match['amount']);

                return ' '.$this->quantity($whole, $fraction, $unit).' ';
            },
            $text,
        ) ?? $text;
    }

    private function quantity(int $whole, string $fraction, string $unit): string
    {
        [$gender, $one, $few, $many, $decimal] = self::UNITS[$unit];

        if (self::hasFraction($fraction)) {
            return self::decimalWords($whole, $fraction).' '.$decimal;
        }

        return self::cardinal($whole, $gender).' '.match (self::pluralClass($whole)) {
            'one' => $one,
            'few' => $few,
            default => $many,
        };
    }

    /**
     * Whatever number is left: "Top 3 akcije", "2026", "3,5". A 1 or a 2 takes the gender of the word
     * after it — "jedna ponuda", "dvije akcije", "dva oglasa" — which the ending gives away well enough
     * for the nouns feeds and headlines use.
     */
    private function numbers(string $text): string
    {
        return preg_replace_callback(
            '/(?<![\d.,])(?<amount>'.self::NUMBER.')(?![\d])(?<rest>\s+\p{L}+)?/u',
            function (array $match): string {
                [$whole, $fraction] = self::parseNumber($match['amount']);
                $rest = $match['rest'] ?? '';

                if (self::hasFraction($fraction)) {
                    return ' '.self::decimalWords($whole, $fraction).$rest;
                }

                return ' '.self::cardinal($whole, $this->genderBefore($whole, mb_trim($rest))).$rest;
            },
            $text,
        ) ?? $text;
    }

    /**
     * Only a number ending in 1 or 2 has a gender. After a 1 the noun is in the nominative singular
     * ("jedna ponuda", "jedno pakiranje", "jedan posao"); after a 2 it is a feminine plural ending in
     * -e or -i ("dvije akcije", "dvije novosti") or a masculine or neuter genitive ("dva oglasa").
     */
    private function genderBefore(int $number, string $word): string
    {
        if ($word === '') {
            return 'm';
        }

        $word = mb_strtolower($word);
        $ending = mb_substr($word, -1);

        return match (self::pluralClass($number) === 'many' ? 0 : $number % 10) {
            1 => match (true) {
                // "posao" is masculine although it ends like a neuter.
                $word === 'posao' => 'm',
                $ending === 'a' => 'f',
                $ending === 'o' || $ending === 'e' => 'n',
                default => 'm',
            },
            2 => $ending === 'e' || $ending === 'i' ? 'f' : 'm',
            default => 'm',
        };
    }

    /**
     * Feeds shout ("LOVRAN", "SPAR"). A model asked to read capitals spells some of them out, so words
     * of four letters or more are lowercased to a name; short ones are acronyms and keep their letters.
     */
    private function shouting(string $text): string
    {
        return preg_replace_callback(
            '/(?<![\p{L}\p{N}])\p{Lu}{2,}(?![\p{L}\p{N}])/u',
            function (array $match): string {
                $word = $match[0];

                if (isset(self::ACRONYMS[$word])) {
                    return self::ACRONYMS[$word];
                }

                return mb_strlen($word) >= 4 ? mb_convert_case(mb_strtolower($word), MB_CASE_TITLE) : $word;
            },
            $text,
        ) ?? $text;
    }

    private function symbols(string $text): string
    {
        $text = str_replace(['&', '+', '@'], [' i ', ' plus ', ' et '], $text);
        $text = preg_replace('/(?<=\p{L})\s*\/\s*(?=\p{L})/u', ' ili ', $text) ?? $text;
        $text = preg_replace('/\s*[→➜➔⟶›»]\s*/u', ', ', $text) ?? $text;
        $text = preg_replace('/\s*[•·|]\s*/u', ', ', $text) ?? $text;
        $text = preg_replace('/\s+[–—-]+\s+/u', ', ', $text) ?? $text;
        $text = preg_replace('/[()\[\]{}„“”"«*#_~^=<>\\\\\/]/u', ' ', $text) ?? $text;
        $text = str_replace(['…', '…'], '.', $text);

        return preg_replace('/(?<![\p{L}\p{N}])[\'’‘`]|[\'’‘`](?![\p{L}\p{N}])/u', ' ', $text) ?? $text;
    }

    /**
     * Spacing and the full stop a sentence needs to be read as one: without it the model trails off.
     */
    private function tidy(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?? $text;
        $text = preg_replace('/([,.;:!?])(?:\s*[,;:])+/u', '$1', $text) ?? $text;
        $text = preg_replace('/([.!?])\s*\.+/u', '$1', $text) ?? $text;
        $text = preg_replace('/(?<=[,.;:!?])(?=\p{L})/u', ' ', $text) ?? $text;
        $text = mb_trim($text, " \t\n\r\0\x0B,;:-");

        if ($text === '') {
            return '';
        }

        // A number at the start of a sentence became a lowercase word; a sentence starts with a capital.
        $text = preg_replace_callback('/(^|[.!?]\s+)(\p{Ll})/u', fn (array $match): string => $match[1].mb_strtoupper($match[2]), $text) ?? $text;

        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }
}
