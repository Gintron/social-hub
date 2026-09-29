<?php

declare(strict_types=1);

namespace Tests\Unit\Voiceover;

use App\Voiceover\Stress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stress as data: what a model may be asked about, whether its answer is the word it was asked about,
 * and how a mark is told to the voice.
 */
final class StressTest extends TestCase
{
    private const LINE = 'Kruh bijeli petsto grama u trgovini Konzum za jedan euro i četrdeset devet centi.';

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function marksThatAreWhatTheySay(): array
    {
        return [
            'an acute on the second vowel' => ['Konzum', 'Kónzum', 1],
            'an acute on the last vowel' => ['Konzum', 'Konzúm', 4],
            // Which mark the model picks is of no consequence, only where.
            'a grave' => ['Konzum', 'Kònzum', 1],
            'a circumflex' => ['Konzum', 'Kônzum', 1],
            'a double grave' => ['Konzum', "Ko\u{030F}nzum", 1],
            'the same mark written as a letter and a combining mark' => ['Konzum', "Ko\u{0301}nzum", 1],
            // The acute of a "ć" belongs to the letter; the one on the "a" before it is the mark.
            'a word with a ć' => ['plaća', 'pláća', 2],
            'the vowel after a ć' => ['plaća', 'plaćá', 4],
            'a word with a caron' => ['četrdeset', 'čétrdeset', 1],
            'a capital' => ['Obitelj', 'Óbitelj', 0],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function marksThatAreNot(): array
    {
        return [
            'no mark at all' => ['Konzum', 'Konzum'],
            'two marks' => ['Konzum', 'Kónzúm'],
            'a letter changed' => ['Konzum', 'Kónzun'],
            'a letter added' => ['Konzum', 'Kónzum!'],
            'a letter dropped' => ['Konzum', 'Kónzu'],
            'the case changed' => ['Konzum', 'kónzum'],
            'a mark on a consonant' => ['Konzum', 'Konźum'],
            'another word' => ['Konzum', 'Lídl'],
            'a mark on the consonant of a ć that was not there' => ['placa', 'plaćá'],
        ];
    }

    public function test_the_words_of_a_line_are_its_runs_of_letters_with_their_places(): void
    {
        $line = 'Šećer za 1,49 € u Konzumu.';

        $words = Stress::words($line);

        $this->assertSame(['Šećer', 'za', 'u', 'Konzumu'], array_column($words, 'text'));

        foreach ($words as $word) {
            $this->assertSame($word['text'], mb_strcut($line, $word['offset'], mb_strlen($word['text'], '8bit')), 'the offset is in bytes and points at the word');
        }
    }

    public function test_only_words_a_stress_can_be_wrong_in_are_worth_asking_about(): void
    {
        foreach (['Konzum', 'trgovini', 'četrdeset', 'Obitelj', 'plaća', 'DEVET', 'Zagreb'] as $word) {
            $this->assertTrue(Stress::markable($word), $word);
        }

        // One vowel: nowhere else to put it. A foreign letter: the spelling already says how it is read.
        foreach (['Kruh', 'u', 'i', 'dm', 'Lidl', 'Café', 'Müller', 'Konzum2', ''] as $word) {
            $this->assertFalse(Stress::markable($word), $word);
        }
    }

    #[DataProvider('marksThatAreWhatTheySay')]
    public function test_a_word_with_one_mark_on_one_vowel_gives_the_place_of_that_vowel(string $word, string $marked, int $at): void
    {
        $this->assertSame($at, Stress::position($word, $marked));
    }

    #[DataProvider('marksThatAreNot')]
    public function test_anything_else_is_not_a_mark_and_is_refused(string $word, string $marked): void
    {
        $this->assertNull(Stress::position($word, $marked));
    }

    public function test_the_stress_is_told_to_the_voice_as_an_acute_or_as_a_capital(): void
    {
        $marks = [['word' => 3, 'at' => 2], ['word' => 5, 'at' => 5], ['word' => 6, 'at' => 1]];

        $this->assertSame(
            'Kruh bijeli petsto gráma u trgovíni Kónzum za jedan euro i četrdeset devet centi.',
            Stress::render(self::LINE, $marks, Stress::ACUTE),
        );
        $this->assertSame(
            'Kruh bijeli petsto grAma u trgovIni KOnzum za jedan euro i četrdeset devet centi.',
            Stress::render(self::LINE, $marks, Stress::CAPS),
        );
        $this->assertSame(self::LINE, Stress::render(self::LINE, $marks, Stress::OFF));
        $this->assertSame(self::LINE, Stress::render(self::LINE, [], Stress::ACUTE));
    }

    public function test_marks_land_on_the_right_word_after_letters_that_take_two_bytes(): void
    {
        $line = 'Čokolada šećer i Konzum';

        // Placed from the last word to the first: the offsets of the earlier ones must not move.
        $this->assertSame('Čokólada šećer i Kónzum', Stress::render($line, [['word' => 0, 'at' => 3], ['word' => 3, 'at' => 1]], Stress::ACUTE));
        $this->assertSame('ČokOlada šećer i KOnzum', Stress::render($line, [['word' => 0, 'at' => 3], ['word' => 3, 'at' => 1]], Stress::CAPS));
    }

    public function test_marks_that_do_not_fit_the_line_they_are_read_against_are_not_placed(): void
    {
        $line = 'Čokolada šećer i Konzum';

        // A word that is not there, a place that is not a vowel (the "K"), a word that is not worth marking.
        $this->assertSame($line, Stress::render($line, [['word' => 99, 'at' => 1], ['word' => 3, 'at' => 0], ['word' => 2, 'at' => 0]], Stress::ACUTE));
    }

    public function test_the_first_mark_for_a_word_is_the_one_that_counts(): void
    {
        $this->assertSame('Kónzum', Stress::render('Konzum', [['word' => 0, 'at' => 1], ['word' => 0, 'at' => 4]], Stress::ACUTE));
    }

    public function test_a_line_is_one_code_point_to_a_letter(): void
    {
        $decomposed = "c\u{030C}etrdeset";

        $this->assertSame('četrdeset', Stress::normalize($decomposed));
        $this->assertSame(['četrdeset'], array_column(Stress::words(Stress::normalize($decomposed)), 'text'));
    }
}
