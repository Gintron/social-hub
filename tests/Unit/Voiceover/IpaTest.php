<?php

declare(strict_types=1);

namespace Tests\Unit\Voiceover;

use App\Voiceover\Ipa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * IPA as data: what a model may be asked about, whether its answer is a transcription of the word it was asked
 * about, and how it is told to the voice.
 */
final class IpaTest extends TestCase
{
    private const LINE = 'Kruh bijeli petsto grama u trgovini Konzum za jedan euro i četrdeset devet centi.';

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function transcriptionsThatAreOfTheWord(): array
    {
        return [
            'as it should be' => ['letka', 'ˈlɛtka', 'ˈlɛtka'],
            'a name' => ['Konzum', 'ˈkɔnzum', 'ˈkɔnzum'],
            // The way people and models write IPA.
            'an apostrophe for the stress mark' => ['letka', "'lɛtka", 'ˈlɛtka'],
            'a typographic apostrophe' => ['letka', '’lɛtka', 'ˈlɛtka'],
            'slashes around it' => ['letka', '/ˈlɛtka/', 'ˈlɛtka'],
            'brackets around it' => ['letka', '[ˈlɛtka]', 'ˈlɛtka'],
            'spaces around it' => ['letka', "  ˈlɛtka \n", 'ˈlɛtka'],
            // Sounds that Croatian spells with one letter, and the vowels' open and close variants.
            'č, š, ž as affricate and fricatives' => ['četrdeset', 'tʃɛtrˈdɛsɛt', 'tʃɛtrˈdɛsɛt'],
            'nj and lj' => ['ljeto', 'ˈʎɛtɔ', 'ˈʎɛtɔ'],
            'v and the g of the IPA' => ['trgovini', 'trˈɡɔʋini', 'trˈɡɔʋini'],
            'length' => ['letak', 'ˈlɛːtak', 'ˈlɛːtak'],
            'a foreign name said the Croatian way' => ['Kaufland', 'ˈkaufland', 'ˈkaufland'],
            'a vowel of another quality' => ['Konzum', 'ˈkonzʊm', 'ˈkonzʊm'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function transcriptionsThatAreNot(): array
    {
        return [
            'another word' => ['listu', 'ˈmatʃku'],
            'a word of another length' => ['letka', 'ˈlɛtkama'],
            'nothing' => ['letka', ''],
            'only brackets' => ['letka', '//'],
            'a sentence' => ['letka', 'ˈlɛtka ˈlɛtka'],
            'a tag' => ['letka', '<phoneme>ˈlɛtka</phoneme>'],
            'a quote that would end the attribute' => ['letka', 'ˈlɛtka"'],
            'digits' => ['letka', 'ˈlɛtka2'],
            'two primary stresses' => ['letka', 'ˈlɛˈtka'],
            'no vowel' => ['letka', 'ˈltk'],
            'the word as it is already written' => ['letka', 'letka'],
            'absurdly long' => ['letka', 'ˈlɛtkaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        ];
    }

    public function test_the_words_of_a_line_are_its_runs_of_letters_with_their_places(): void
    {
        $line = 'Šećer za 1,49 € u Konzumu.';

        $words = Ipa::words($line);

        $this->assertSame(['Šećer', 'za', 'u', 'Konzumu'], array_column($words, 'text'));

        foreach ($words as $word) {
            $this->assertSame($word['text'], mb_strcut($line, $word['offset'], mb_strlen($word['text'], '8bit')), 'the offset is in bytes and points at the word');
        }
    }

    public function test_only_words_that_are_more_than_a_preposition_are_worth_asking_about(): void
    {
        foreach (['Konzum', 'trgovini', 'četrdeset', 'Obitelj', 'plaća', 'DEVET', 'Lidl', 'Kruh', 'Café', 'Müller'] as $word) {
            $this->assertTrue(Ipa::markable($word), $word);
        }

        foreach (['u', 'i', 'za', 'dm', 'Konzum2', '', 'Лидл'] as $word) {
            $this->assertFalse(Ipa::markable($word), $word);
        }
    }

    #[DataProvider('transcriptionsThatAreOfTheWord')]
    public function test_a_transcription_of_the_word_is_made_fit_to_send(string $word, string $given, string $expected): void
    {
        $this->assertSame($expected, Ipa::clean($word, $given));
    }

    #[DataProvider('transcriptionsThatAreNot')]
    public function test_anything_else_is_not_a_transcription_of_the_word_and_is_refused(string $word, string $given): void
    {
        $this->assertNull(Ipa::clean($word, $given));
    }

    public function test_what_a_person_typed_only_has_to_be_something_that_can_be_put_in_a_text(): void
    {
        $this->assertSame('ˈlɛtka', Ipa::sanitize("'lɛtka"));
        $this->assertSame('ˈlɛːtak', Ipa::sanitize('/ˈlɛːtak/'));
        // A person may respell a word as they please; it is heard, not compared.
        $this->assertSame('ˈkɔnzum', Ipa::sanitize('ˈkɔnzum'));

        foreach (['', ' ', 'ˈlɛt ka', 'ˈlɛtka"', '<b>ˈlɛtka</b>', 'ˈlɛˈtka', 'ˈltk', 'ˈlɛtka2', str_repeat('a', 61)] as $typed) {
            $this->assertNull(Ipa::sanitize($typed), $typed);
        }
    }

    public function test_the_words_a_person_gave_an_ipa_for_are_found_whole_and_in_any_case(): void
    {
        $line = 'S letka u Konzumu i Letka u konzum.';

        $this->assertSame(
            [['word' => 1, 'ipa' => 'ˈlɛtka'], ['word' => 5, 'ipa' => 'ˈlɛtka'], ['word' => 7, 'ipa' => 'ˈkɔnzum']],
            Ipa::marksIn($line, ['letka' => 'ˈlɛtka', 'KONZUM' => 'ˈkɔnzum']),
        );
        $this->assertSame([], Ipa::marksIn($line, []));
        $this->assertSame([], Ipa::marksIn($line, ['letku' => 'ˈlɛtku']), 'another form of the word is not the word');
    }

    public function test_a_transcription_is_compared_with_the_word_it_is_for_and_not_with_its_neighbour(): void
    {
        $this->assertTrue(Ipa::resembles('mačku', 'ˈmatʃku'));
        $this->assertFalse(Ipa::resembles('listu', 'ˈmatʃku'));
        $this->assertTrue(Ipa::resembles('Konzum', 'ˈkɔnzum'));
        $this->assertFalse(Ipa::resembles('Konzum', 'ˈlidl'));
        $this->assertFalse(Ipa::resembles('Konzum', 'ˈ'));
    }

    public function test_the_ipa_is_told_to_the_voice_as_a_tag_in_slashes_or_bare(): void
    {
        $marks = [['word' => 3, 'ipa' => 'ˈɡraːma'], ['word' => 6, 'ipa' => 'ˈkɔnzum']];

        $this->assertSame(
            'Kruh bijeli petsto <phoneme alphabet="ipa" ph="ˈɡraːma">grama</phoneme> u trgovini <phoneme alphabet="ipa" ph="ˈkɔnzum">Konzum</phoneme> za jedan euro i četrdeset devet centi.',
            Ipa::render(self::LINE, $marks, Ipa::TAG),
        );
        $this->assertSame('Kruh bijeli petsto /ˈɡraːma/ u trgovini /ˈkɔnzum/ za jedan euro i četrdeset devet centi.', Ipa::render(self::LINE, $marks, Ipa::SLASH));
        $this->assertSame('Kruh bijeli petsto ˈɡraːma u trgovini ˈkɔnzum za jedan euro i četrdeset devet centi.', Ipa::render(self::LINE, $marks, Ipa::BARE));
        $this->assertSame(self::LINE, Ipa::render(self::LINE, $marks, Ipa::OFF));
        $this->assertSame(self::LINE, Ipa::render(self::LINE, [], Ipa::SLASH));
    }

    public function test_marks_land_on_the_right_word_after_letters_that_take_two_bytes(): void
    {
        $line = 'Čokolada šećer i Konzum';

        // Placed from the last word to the first: the offsets of the earlier ones must not move.
        $this->assertSame('/tʃɔkɔˈlada/ šećer i /ˈkɔnzum/', Ipa::render($line, [['word' => 0, 'ipa' => 'tʃɔkɔˈlada'], ['word' => 3, 'ipa' => 'ˈkɔnzum']], Ipa::SLASH));
    }

    public function test_marks_that_do_not_fit_the_line_they_are_read_against_are_not_placed(): void
    {
        $line = 'Čokolada šećer i Konzum';

        // A word that is not there, a word that is not worth transcribing, and something that is not IPA at all.
        $this->assertSame($line, Ipa::render($line, [['word' => 99, 'ipa' => 'ˈx'], ['word' => 2, 'ipa' => 'ˈi'], ['word' => 3, 'ipa' => '"><script>']], Ipa::TAG));
    }

    public function test_the_first_mark_for_a_word_is_the_one_that_counts(): void
    {
        $this->assertSame('/ˈkɔnzum/', Ipa::render('Konzum', [['word' => 0, 'ipa' => 'ˈkɔnzum'], ['word' => 0, 'ipa' => 'kɔnˈzum']], Ipa::SLASH));
    }

    public function test_a_line_is_one_code_point_to_a_letter(): void
    {
        $decomposed = "c\u{030C}etrdeset";

        $this->assertSame('četrdeset', Ipa::normalize($decomposed));
        $this->assertSame(['četrdeset'], array_column(Ipa::words(Ipa::normalize($decomposed)), 'text'));
    }
}
