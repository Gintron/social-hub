<?php

declare(strict_types=1);

namespace App\Voiceover;

use Normalizer;

/**
 * Stress in a line of Croatian, as data: which words a model may be asked about, whether the mark it
 * answers with is what it claims to be, and how a mark is told to the voice.
 *
 * A mark is a place — the number of a word in its line and of a vowel in that word — and not a character.
 * The character depends on what a voice takes for stress (an acute, a capital: the `style`) and can be
 * changed without asking the model again.
 */
final class Stress
{
    /** The vowel carries an acute: "kúća". The way stress is written in a dictionary. */
    public const ACUTE = 'acute';

    /** The vowel is a capital: "kUća". What ElevenLabs suggests for models that take no phoneme tags. */
    public const CAPS = 'caps';

    /** The line goes to the voice as it is. */
    public const OFF = 'off';

    /** The line as it is first, so a comparison starts from what the voice would say without any help. */
    public const STYLES = [self::OFF, self::ACUTE, self::CAPS];

    /**
     * Combining marks a model may write for stress: acute, grave, circumflex, double grave, inverted breve and
     * macron. Which of them it picks does not matter — only where — so all are read and none is kept.
     */
    private const MARKS = '\x{0300}\x{0301}\x{0302}\x{030F}\x{0311}\x{0304}';

    /**
     * The line as the rest of the hub sees it: one code point per letter. A "č" typed as "c" and a caron would
     * split a word in two.
     */
    public static function normalize(string $line): string
    {
        $normalized = Normalizer::normalize($line, Normalizer::FORM_C);

        return $normalized === false ? $line : $normalized;
    }

    /**
     * Every run of letters in a line, in order — what a model is shown by number and what a mark is placed on.
     *
     * @return list<array{text: string, offset: int}> `offset` is in bytes.
     */
    public static function words(string $line): array
    {
        preg_match_all('/\p{L}+/u', $line, $found, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $word): array => ['text' => $word[0], 'offset' => $word[1]], $found[0]);
    }

    /**
     * Whether a word is one a model is asked about: written in the letters of Croatian and long enough for
     * the stress to fall in more than one place. A word with an "é" or a "ü" is a foreign name whose
     * spelling already says how it is read.
     */
    public static function markable(string $word): bool
    {
        return preg_match('/^[A-Za-zČčĆćĐđŠšŽž]+$/u', $word) === 1
            && preg_match_all('/[aeiouAEIOU]/', $word) >= 2;
    }

    /**
     * Where the stress is in `$marked` — a word with one mark on one vowel — as the place of that vowel in
     * `$word`; null when it is anything else: another word, no mark, two marks, a mark on a consonant.
     * A model that changes a letter does not get to change the word.
     */
    public static function position(string $word, string $marked): ?int
    {
        $decomposed = Normalizer::normalize($marked, Normalizer::FORM_D);

        if ($decomposed === false) {
            return null;
        }

        $plain = '';
        $letters = 0;
        $previous = '';
        $marks = 0;
        $at = null;

        foreach (preg_split('//u', $decomposed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (preg_match('/^\p{M}$/u', $character) !== 1) {
                $plain .= $character;
                $previous = $character;
                $letters++;

                continue;
            }

            // The acute of a "ć" is a part of the letter; the acute of an "á" is the mark.
            if (preg_match('/^['.self::MARKS.']$/u', $character) === 1 && self::vowel($previous)) {
                $marks++;
                $at = $letters - 1;

                continue;
            }

            $plain .= $character;
        }

        if ($marks !== 1 || $at === null || self::normalize($plain) !== $word || ! self::vowel(mb_substr($word, $at, 1))) {
            return null;
        }

        return $at;
    }

    /**
     * The line as the voice is to read it, with the stress told in `$style`.
     *
     * @param  list<array{word: int, at: int}>  $marks
     */
    public static function render(string $line, array $marks, string $style): string
    {
        if ($style === self::OFF || $marks === []) {
            return $line;
        }

        $words = self::words($line);
        $places = [];

        foreach ($marks as $mark) {
            $places[(int) $mark['word']] ??= (int) $mark['at'];
        }

        // From the last word to the first, so the places of the ones before it stay where they were.
        krsort($places);

        foreach ($places as $number => $at) {
            $word = $words[$number] ?? null;
            $letter = $word === null ? '' : mb_substr($word['text'], $at, 1);

            // Marks made for another line, or a word that is no longer there, are not put anywhere.
            if ($word === null || ! self::markable($word['text']) || ! self::vowel($letter)) {
                continue;
            }

            $stressed = $style === self::CAPS ? mb_strtoupper($letter) : self::normalize($letter."\u{0301}");
            $marked = mb_substr($word['text'], 0, $at).$stressed.mb_substr($word['text'], $at + 1);
            $line = substr_replace($line, $marked, $word['offset'], mb_strlen($word['text'], '8bit'));
        }

        return $line;
    }

    private static function vowel(string $letter): bool
    {
        return preg_match('/^[aeiouAEIOU]$/', $letter) === 1;
    }
}
