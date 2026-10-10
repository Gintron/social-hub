<?php

declare(strict_types=1);

namespace Tests\Unit\Voiceover;

use App\Voiceover\SpokenCroatian;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a voice-over says is only as right as the words it is given. Every case here is a shape the
 * feeds really produce, and the expected text is what a Croatian speaker would read out.
 */
final class SpokenCroatianTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function cardinals(): array
    {
        return [
            'zero' => [0, 'nula'],
            'one' => [1, 'jedan'],
            'two' => [2, 'dva'],
            'teen' => [11, 'jedanaest'],
            'twenty-one' => [21, 'dvadeset jedan'],
            'twenty-two' => [22, 'dvadeset dva'],
            'ninety-nine' => [99, 'devedeset devet'],
            'hundred' => [100, 'sto'],
            'hundred and one' => [101, 'sto jedan'],
            'two hundred' => [200, 'dvjesto'],
            'nine hundred ninety-nine' => [999, 'devetsto devedeset devet'],
            'one thousand' => [1000, 'tisuću'],
            'one thousand and one' => [1001, 'tisuću jedan'],
            'a euro price above a thousand' => [1299, 'tisuću dvjesto devedeset devet'],
            'two thousand' => [2000, 'dvije tisuće'],
            'a year' => [2026, 'dvije tisuće dvadeset šest'],
            'three thousand' => [3000, 'tri tisuće'],
            'five thousand' => [5000, 'pet tisuća'],
            'eleven thousand' => [11000, 'jedanaest tisuća'],
            'twenty-one thousand' => [21000, 'dvadeset jedna tisuća'],
            'twenty-two thousand' => [22000, 'dvadeset dvije tisuće'],
            'a hundred thousand' => [100000, 'sto tisuća'],
            'a million' => [1000000, 'jedan milijun'],
            'two and a half million' => [2500000, 'dva milijuna petsto tisuća'],
        ];
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function amounts(): array
    {
        return [
            'one euro forty-nine' => [1, 49, 'jedan euro i četrdeset devet centi'],
            'round euros' => [2, 0, 'dva eura'],
            'cents only' => [0, 99, 'devedeset devet centi'],
            'ten euros fifty' => [12, 50, 'dvanaest eura i pedeset centi'],
            'twenty-one euros one cent' => [21, 1, 'dvadeset jedan euro i jedan cent'],
            'twenty-two euros two cents' => [22, 2, 'dvadeset dva eura i dva centa'],
            'five cents' => [5, 5, 'pet eura i pet centi'],
            'eleven cents' => [3, 11, 'tri eura i jedanaest centi'],
            'over a thousand' => [1299, 0, 'tisuću dvjesto devedeset devet eura'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sentences(): array
    {
        return [
            // Prices
            'a price' => ['1,49 €', 'Jedan euro i četrdeset devet centi.'],
            'a price with the sign first' => ['Samo €2,50', 'Samo dva eura i pedeset centi.'],
            'a price written with a dot' => ['7.50 €', 'Sedam eura i pedeset centi.'],
            'a thousands price' => ['1.299,00 €', 'Tisuću dvjesto devedeset devet eura.'],
            'a price per kilogram' => ['9,98 €/kg', 'Devet eura i devedeset osam centi po kilogramu.'],
            'a price per litre with spaces' => ['2,29 € / l', 'Dva eura i dvadeset devet centi po litri.'],
            'pay per hour' => ['7.00 €/H', 'Sedam eura po satu.'],
            'a pay range' => ['7,00 – 8,00 €/H', 'Od sedam do osam eura po satu.'],
            'a range with cents' => ['7,50 - 8,50 €/H', 'Od sedam eura i pedeset centi do osam eura i pedeset centi po satu.'],
            'a price with the currency word' => ['5 EUR', 'Pet eura.'],
            // Percentages
            'a discount' => ['-25 %', 'Minus dvadeset pet posto.'],
            'a discount with the unicode minus' => ['−35%', 'Minus trideset pet posto.'],
            'a share' => ['do 60 %', 'Do šezdeset posto.'],
            'a fractional share' => ['12,5 %', 'Dvanaest zarez pet posto.'],
            'a percentage range' => ['20-30 %', 'Od dvadeset do trideset posto.'],
            // Units
            'grams' => ['Kruh 500 g', 'Kruh petsto grama.'],
            'one kilogram' => ['1 kg', 'Jedan kilogram.'],
            'two kilograms' => ['2 kg', 'Dva kilograma.'],
            'a decimal weight' => ['1,5 kg', 'Jedan zarez pet kilograma.'],
            'feminine litres' => ['2 l', 'Dvije litre.'],
            'five litres' => ['5 l', 'Pet litara.'],
            'a fraction of a litre' => ['0,5 l', 'Nula zarez pet litre.'],
            'a pack' => ['6x0,33L', 'Šest puta nula zarez trideset tri litre.'],
            'pieces' => ['3 kom', 'Tri komada.'],
            'fat percentage in a product name' => ['Jogurt 3,2% m.m.', 'Jogurt tri zarez dva posto mliječne masti.'],
            // Dates and times
            'a date with a year' => ['Vrijedi do 30.09.2026.', 'Vrijedi do tridesetog rujna.'],
            'a date without a year' => ['do 5.10.', 'Do petog listopada.'],
            'a date in a single-digit form' => ['15.9.2026.', 'Petnaestog rujna.'],
            'a time' => ['u 18:30', 'U osamnaest i trideset.'],
            'a full hour' => ['u 9:00', 'U devet sati.'],
            // Counts
            'a count before a feminine plural' => ['Top 2 akcije', 'Top dvije akcije.'],
            'a count before a masculine genitive' => ['2 oglasa', 'Dva oglasa.'],
            'a count of three' => ['Top 3 akcije u Kauflandu', 'Top tri akcije u Kauflandu.'],
            'a feminine one' => ['1 ponuda', 'Jedna ponuda.'],
            'a masculine one' => ['1 posao', 'Jedan posao.'],
            'a year in a sentence' => ['Sezona 2026', 'Sezona dvije tisuće dvadeset šest.'],
            // Job ads
            'both genders' => ['Konobar/ica', 'Konobar ili konobarica.'],
            'a feminine that drops a letter' => ['Radnik/ca u skladištu', 'Radnik ili radnica u skladištu.'],
            'a gender mark' => ['Vozač (m/ž)', 'Vozač.'],
            'a company suffix' => ['Hotel Adriatic d.o.o.', 'Hotel Adriatic.'],
            'a sole trader suffix' => ['Alfa j.d.o.o.', 'Alfa.'],
            // Names and symbols
            'a shouted place' => ['LOVRAN', 'Lovran.'],
            'a shouted chain' => ['SPAR', 'Spar.'],
            'a short acronym keeps its letters' => ['Rad na TV', 'Rad na te ve.'],
            'a known acronym' => ['PDV uključen', 'Pe de ve uključen.'],
            'emoji are dropped' => ['🛒 Kruh bijeli', 'Kruh bijeli.'],
            // Dates the way prose writes them
            'a day with its month named' => ['Vrijedi do 3. listopada', 'Vrijedi do trećeg listopada.'],
            'days of one month' => ['Vrijedi od 3. do 9. listopada', 'Vrijedi od trećeg do devetog listopada.'],
            'days of one month with a dash' => ['Akcija 3.-9. listopada', 'Akcija od trećeg do devetog listopada.'],
            'a range of dates in one month' => ['Ponuda vrijedi 15.10.-21.10.2026.', 'Ponuda vrijedi od petnaestog do dvadeset prvog listopada.'],
            'a range of dates written short' => ['Ponuda 15.-21.10.', 'Ponuda od petnaestog do dvadeset prvog listopada.'],
            'a range of dates across two months' => ['Akcija 28.9.-3.10.', 'Akcija od dvadeset osmog rujna do trećeg listopada.'],
            'a month name alone is not a date' => ['Top 3 listopadski hitovi', 'Top tri listopadski hitovi.'],
            'bullets become pauses' => ['Smještaj • Obrok', 'Smještaj, Obrok.'],
            'an arrow becomes a pause' => ['Letak → tvoja lista', 'Letak, tvoja lista.'],
            'an ampersand' => ['Voće & povrće', 'Voće i povrće.'],
            'a slash between places' => ['Split/Zagreb', 'Split ili Zagreb.'],
            'a domain' => ['Prijavi se na studentski-poslovi.hr', 'Prijavi se na studentski poslovi točka ha er.'],
            'a domain with a scheme' => ['https://www.uselisto.com', 'Uselisto točka kom.'],
            'an abbreviation' => ['npr. kruh', 'Na primjer kruh.'],
            // Sentence shape
            'a full stop is added' => ['Preuzmi Listo', 'Preuzmi Listo.'],
            'an existing full stop is kept' => ['Preuzmi Listo.', 'Preuzmi Listo.'],
            'an exclamation is kept' => ['Samo danas!', 'Samo danas!'],
            'nothing to say' => ['🛒 •', ''],
            'diacritics survive' => ['Čokolada, šećer, đumbir', 'Čokolada, šećer, đumbir.'],
        ];
    }

    #[DataProvider('cardinals')]
    public function test_numbers_are_spelled_in_croatian(int $number, string $words): void
    {
        $this->assertSame($words, SpokenCroatian::cardinal($number));
    }

    public function test_one_and_two_take_the_gender_of_the_noun_they_count(): void
    {
        $this->assertSame('jedna', SpokenCroatian::cardinal(1, 'f'));
        $this->assertSame('dvije', SpokenCroatian::cardinal(2, 'f'));
        $this->assertSame('dvadeset dvije', SpokenCroatian::cardinal(22, 'f'));
        $this->assertSame('jedno', SpokenCroatian::cardinal(1, 'n'));
        $this->assertSame('dva', SpokenCroatian::cardinal(2, 'n'));
    }

    #[DataProvider('amounts')]
    public function test_an_amount_of_euros_agrees_with_its_numbers(int $euros, int $cents, string $words): void
    {
        $this->assertSame($words, SpokenCroatian::money($euros, $cents));
    }

    #[DataProvider('sentences')]
    public function test_written_text_becomes_what_a_speaker_would_read(string $written, string $spoken): void
    {
        $this->assertSame($spoken, (new SpokenCroatian)->speak($written));
    }

    public function test_a_whole_deal_line_reads_naturally(): void
    {
        $this->assertSame(
            'Kruh bijeli petsto grama u trgovini Konzum za jedan euro i četrdeset devet centi. Popust dvadeset pet posto. Vrijedi do tridesetog rujna.',
            (new SpokenCroatian)->speak('Kruh bijeli 500 g u trgovini Konzum za 1,49 €. Popust 25 %. Vrijedi do 30.09.2026.'),
        );
    }

    public function test_a_date_is_said_in_the_case_that_follows_do(): void
    {
        $this->assertSame('prvog siječnja', SpokenCroatian::date(CarbonImmutable::parse('2027-01-01')));
        $this->assertSame('dvadeset trećeg studenoga', SpokenCroatian::date(CarbonImmutable::parse('2026-11-23')));
        $this->assertSame('trideset prvog prosinca', SpokenCroatian::date(CarbonImmutable::parse('2026-12-31')));
    }

    public function test_an_impossible_date_is_left_alone_rather_than_invented(): void
    {
        $this->assertStringNotContainsString('rujna', (new SpokenCroatian)->speak('45.09.'));
    }

    public function test_a_brand_pronunciation_wins_over_the_general_rules(): void
    {
        $spoken = (new SpokenCroatian)->speak('Nova ponuda u DM-u i u Sparu', ['DM-u' => 'de emu', 'Sparu' => 'Sparu']);

        $this->assertSame('Nova ponuda u de emu i u Sparu.', $spoken);
    }

    public function test_the_words_a_pronunciation_says_are_listed_in_lower_case_and_the_written_side_is_not(): void
    {
        $this->assertSame(['kon', 'zum', 'letka'], SpokenCroatian::pronounced(['Konzum' => 'Kon-zum', 'letku' => 'LETKA']));
    }

    public function test_a_pronunciation_only_matches_whole_words_and_ignores_case(): void
    {
        $spoken = (new SpokenCroatian)->speak('Listo, listopad i LISTO', ['listo' => 'Lisdo']);

        $this->assertSame('Lisdo, listopad i Lisdo.', $spoken);
    }

    public function test_no_digit_reaches_the_speech_model(): void
    {
        $spoken = (new SpokenCroatian)->speak('Pileći file 1 kg, 12,99 €/kg, −40 %, do 30.9., 3 kom, 2026, 1.299,00 €, 7,00 – 8,00 €/H, 24x0,5 l');

        $this->assertSame(0, preg_match('/\d/', $spoken), $spoken);
    }
}
