<?php

declare(strict_types=1);

namespace App\Voiceover;

use Normalizer;

/**
 * How a word is said, as IPA, in a line a voice is about to read — as data: which words have an IPA (a person
 * chose it, or a model wrote it), whether it is a transcription fit to send, and how it is told to the voice.
 *
 * A mark is the number of a word in its line and the IPA it was given; the line itself is never rewritten. The hub
 * puts the IPA into the text the voice receives (ElevenLabs v4 reads it there, no dictionary needed), in the style
 * the brand has chosen.
 */
final class Ipa
{
    /** The line goes to the voice as it is. */
    public const OFF = 'off';

    /** `<phoneme alphabet="ipa" ph="ˈlɛtka">letka</phoneme>`: the word stays, with its sound beside it. About 45 characters more for every word, and ElevenLabs bills by the character. */
    public const TAG = 'tag';

    /** `/ˈlɛtka/` in place of the word: two characters more for every word. */
    public const SLASH = 'slash';

    /** `ˈlɛtka` in place of the word: no characters more. */
    public const BARE = 'bare';

    /** The line as it is first, so a comparison starts from what the voice would say without any help. */
    public const STYLES = [self::OFF, self::TAG, self::SLASH, self::BARE];

    /** How IPA is written for Croatian, as told to a model that writes it (Phonetizer, IpaSuggester). */
    public const NOTATION = <<<'TXT'
Zapis: fonemska IPA hrvatskoga, bez kosih i uglatih zagrada i bez razmaka. Primarni naglasak je znak ˈ (U+02C8) neposredno ispred naglašenog sloga; označi samo mjesto naglaska, ne razlikuj silazni od uzlaznog. Dužinu (ː) piši samo ako si siguran. Glasovi: e = ɛ, o = ɔ, č = tʃ, dž = dʒ, š = ʃ, ž = ʒ, ć = tɕ, đ = dʑ, lj = ʎ, nj = ɲ, v = ʋ, h = x, a ostalo kao u pravopisu (a, b, d, f, g, i, j, k, l, m, n, p, r, s, t, u, z). Primjeri: letka → ˈlɛtka; mačku → ˈmatʃku.
TXT;

    /** What is in a transcription: letters (IPA's are letters, and so are its stress and length marks), combining marks, a syllable dot, a tie bar. Nothing a tag or a sentence could be made of. */
    private const ALLOWED = '/^\p{L}[\p{L}\p{M}.\x{035C}\x{0361}]*$/u';

    /** Sounds that are one letter in Croatian spelling, as ASCII keys for comparing a transcription with its word. */
    private const WORD_KEYS = ['dž' => 'J', 'lj' => 'L', 'nj' => 'N', 'č' => 'C', 'ć' => 'K', 'š' => 'S', 'ž' => 'Z', 'đ' => 'D'];

    private const IPA_KEYS = [
        'tʃ' => 'C', 'tɕ' => 'K', 'dʒ' => 'J', 'dʑ' => 'D', 'ʃ' => 'S', 'ʒ' => 'Z', 'ʎ' => 'L', 'ɲ' => 'N', 'ʋ' => 'v', 'x' => 'h',
        'ɡ' => 'g', 'ɫ' => 'l', 'ɾ' => 'r', 'ʁ' => 'r', 'ɛ' => 'e', 'ɔ' => 'o', 'ə' => 'e', 'ɪ' => 'i', 'ʊ' => 'u', 'ɐ' => 'a',
        'ɑ' => 'a', 'æ' => 'e', 'ɨ' => 'i', 'ʏ' => 'u', 'ø' => 'o', 'œ' => 'o', 'ŋ' => 'n', 'ɱ' => 'm', 'ʔ' => '',
    ];

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
     * Every run of letters in a line, in order — what a model is shown by number and what a transcription is placed on.
     *
     * @return list<array{text: string, offset: int}> `offset` is in bytes.
     */
    public static function words(string $line): array
    {
        preg_match_all('/\p{L}+/u', $line, $found, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $word): array => ['text' => $word[0], 'offset' => $word[1]], $found[0]);
    }

    /**
     * Whether a word is one a model is asked about: written in Latin letters and long enough to be a word rather
     * than a preposition. Which of those it transcribes is its business (the instructions say: the ones a voice
     * would say wrongly).
     */
    public static function markable(string $word): bool
    {
        return preg_match('/^\p{Latin}{3,}$/u', $word) === 1;
    }

    /**
     * IPA as a person or a model writes it, made fit to send to a voice; null when it is not one word's transcription.
     *
     * It is cleaned the way IPA is typed (an apostrophe for the stress mark, brackets around it) and then held to what
     * a transcription is: only letters and marks, no spaces, no quotes or tags that could end the one it is put in, one
     * primary stress, a vowel in it.
     */
    public static function sanitize(string $ipa): ?string
    {
        $ipa = mb_trim(self::normalize($ipa));
        $ipa = str_replace(["'", '’', '´', '`'], 'ˈ', $ipa);

        if (preg_match('/^([\/\[])(.*)([\/\]])$/su', $ipa, $wrapped) === 1) {
            $ipa = mb_trim($wrapped[2]);
        }

        if ($ipa === ''
            || mb_strlen($ipa) > 60
            || preg_match(self::ALLOWED, $ipa) !== 1
            || mb_substr_count($ipa, 'ˈ') > 1
            || preg_match('/[aeiouɛɔəɪʊɐɑæɨʏøœ]/u', $ipa) !== 1) {
            return null;
        }

        return $ipa;
    }

    /**
     * What a model wrote as the IPA of `$word`, made fit to send to a voice; null when it is not a transcription of that
     * word: not fit (see sanitize), absurdly long for it, the word as it is already written, or not resembling it. A
     * transcription of another word ("mačku" given for "listu") is the one mistake that would change what is said, and a
     * voice cannot be asked to refuse it. (What a person typed for a word they chose is not held to resemble it: it is
     * theirs, and heard.)
     */
    public static function clean(string $word, string $ipa): ?string
    {
        $ipa = self::sanitize($ipa);

        if ($ipa === null
            || mb_strlen($ipa) > 2 * mb_strlen($word) + 4
            // The word itself says nothing a voice does not already read.
            || self::normalize(mb_strtolower($word)) === $ipa
            || ! self::resembles($word, $ipa)) {
            return null;
        }

        return $ipa;
    }

    /**
     * The words of a line that a person gave an IPA for, as marks: every word that is one of `$words`, in any case and
     * whole (a rule for "letka" is not one for "letku" — each form has its own).
     *
     * @param  array<string, string>  $words  Word => IPA, as VoiceoverSettings keeps them.
     * @return list<array{word: int, ipa: string}>
     */
    public static function marksIn(string $line, array $words): array
    {
        if ($words === []) {
            return [];
        }

        $known = [];

        foreach ($words as $word => $ipa) {
            $known[mb_strtolower(self::normalize((string) $word))] = $ipa;
        }

        $marks = [];

        foreach (self::words(self::normalize($line)) as $number => $word) {
            $ipa = $known[mb_strtolower($word['text'])] ?? null;

            if ($ipa !== null) {
                $marks[] = ['word' => $number, 'ipa' => $ipa];
            }
        }

        return $marks;
    }

    /**
     * Whether a transcription is, give or take a vowel or a sound, of this word: the two are brought to the same rough
     * spelling and compared. It lets through every honest transcription of Croatian and most names, and stops another word.
     */
    public static function resembles(string $word, string $ipa): bool
    {
        $word = self::key($word, self::WORD_KEYS);
        $ipa = self::key($ipa, self::IPA_KEYS);

        if ($word === '' || $ipa === '') {
            return false;
        }

        // A sound or a vowel off in every four letters: an honest transcription of a name is that far from its spelling at most.
        return levenshtein($word, $ipa) <= max(1, intdiv(mb_strlen($word), 4));
    }

    /**
     * The line as the voice is to read it, with the transcriptions told in `$style`.
     *
     * @param  list<array{word: int, ipa: string}>  $marks
     */
    public static function render(string $line, array $marks, string $style): string
    {
        if ($style === self::OFF || $marks === []) {
            return $line;
        }

        $words = self::words($line);
        $places = [];

        foreach ($marks as $mark) {
            $places[(int) $mark['word']] ??= (string) $mark['ipa'];
        }

        // From the last word to the first, so the places of the ones before it stay where they were.
        krsort($places);

        foreach ($places as $number => $ipa) {
            $word = $words[$number] ?? null;

            // Marks made for another line, or a word that is no longer there, are not put anywhere.
            if ($word === null || ! self::markable($word['text']) || preg_match(self::ALLOWED, $ipa) !== 1) {
                continue;
            }

            $said = match ($style) {
                self::TAG => '<phoneme alphabet="ipa" ph="'.$ipa.'">'.$word['text'].'</phoneme>',
                self::SLASH => '/'.$ipa.'/',
                default => $ipa,
            };

            $line = substr_replace($line, $said, $word['offset'], mb_strlen($word['text'], '8bit'));
        }

        return $line;
    }

    /**
     * A word or a transcription as a string of ASCII letters in which what is the same sound in both comes out the same.
     *
     * @param  array<string, string>  $keys  The sounds that have one letter in the other writing.
     */
    private static function key(string $text, array $keys): string
    {
        $text = mb_strtolower(self::normalize($text));
        $text = strtr($text, $keys);
        // What is left: the stress and length marks, and the accents of letters that are ASCII underneath.
        $text = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;

        return (string) preg_replace('/[^A-Za-z]/', '', $text);
    }
}
