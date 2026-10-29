<?php

declare(strict_types=1);

namespace Tests\Unit\Voiceover;

use App\Voiceover\SpokenGerman;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a German brand's voice receives: words for every price, percentage, date, pack and number, so the model never
 * decides on its own how "1,99 €" is said.
 */
final class SpokenGermanTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function lines(): iterable
    {
        yield 'euro and cents' => ['Vollmilch bei Billa um 1,99 €.', 'Vollmilch bei Billa um ein Euro neunundneunzig.'];
        yield 'cents only' => ['Nur 0,99 €.', 'Nur neunundneunzig Cent.'];
        yield 'whole euros' => ['Ab 12 €, sonst € 2,00.', 'Ab zwölf Euro, sonst zwei Euro.'];
        yield 'thousands' => ['Für 1.299,50 €', 'Für eintausendzweihundertneunundneunzig Euro fünfzig'];
        yield 'price per unit' => ['Am günstigsten: Hofer, 9,98 €/kg und 0,99 €/l.', 'Am günstigsten: Hofer, neun Euro achtundneunzig pro Kilo und neunundneunzig Cent pro Liter.'];
        yield 'discount' => ['Minus 30 %. Rabatte bis zu −45 %.', 'Minus dreißig Prozent. Rabatte bis zu minus fünfundvierzig Prozent.'];
        yield 'date with year' => ['Gültig bis zum 25.10.2026.', 'Gültig bis zum fünfundzwanzigsten Oktober zweitausendsechsundzwanzig.'];
        yield 'date without year' => ['Ab 1.1. und bis 7.10.', 'Ab ersten Jänner und bis siebten Oktober'];
        yield 'packs' => ['500 g, 1 kg oder 0,5 l', 'fünfhundert Gramm, ein Kilo oder null Komma fünf Liter'];
        yield 'ranges' => ['2–4 Stück', 'zwei bis vier Stück'];
        yield 'numbers' => ['21 Angebote, 101 Seiten, 3,5 Prozent', 'einundzwanzig Angebote, einhunderteins Seiten, drei Komma fünf Prozent'];
        yield 'emoji and hashtags' => ['Neu 🛒 #prospekt', 'Neu prospekt'];
        yield 'and' => ['Obst & Gemüse', 'Obst und Gemüse'];
    }

    #[DataProvider('lines')]
    public function test_a_line_reaches_the_voice_as_words(string $written, string $spoken): void
    {
        $this->assertSame($spoken, (new SpokenGerman)->speak($written));
    }

    public function test_the_brands_pronunciations_come_first(): void
    {
        $this->assertSame('Hol dir Lisstoh.', (new SpokenGerman)->speak('Hol dir Listo.', ['Listo' => 'Lisstoh']));
    }

    public function test_cardinals_and_ordinals(): void
    {
        $this->assertSame('null', SpokenGerman::cardinal(0));
        $this->assertSame('eins', SpokenGerman::cardinal(1));
        $this->assertSame('einundzwanzig', SpokenGerman::cardinal(21));
        $this->assertSame('dreihundertsiebenundvierzig', SpokenGerman::cardinal(347));
        $this->assertSame('zweitausendsechsundzwanzig', SpokenGerman::cardinal(2026));
        $this->assertSame('eine Million zweihunderttausend', SpokenGerman::cardinal(1_200_000));
        $this->assertSame('ersten', SpokenGerman::ordinal(1));
        $this->assertSame('dritten', SpokenGerman::ordinal(3));
        $this->assertSame('zwölften', SpokenGerman::ordinal(12));
        $this->assertSame('zwanzigsten', SpokenGerman::ordinal(20));
        $this->assertSame('einunddreißigsten', SpokenGerman::ordinal(31));
    }
}
