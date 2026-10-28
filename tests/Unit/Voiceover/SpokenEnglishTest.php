<?php

declare(strict_types=1);

namespace Tests\Unit\Voiceover;

use App\Voiceover\SpokenEnglish;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an English character's voice receives: words for every amount, percentage, time, year and number, so the model
 * never decides on its own how "$1,299.99" is said.
 */
final class SpokenEnglishTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function lines(): iterable
    {
        yield 'dollars and cents' => ['It costs $1,299.99 today.', 'It costs one thousand two hundred ninety-nine dollars and ninety-nine cents today.'];
        yield 'one dollar' => ['Just $1.', 'Just one dollar.'];
        yield 'cents only' => ['Only $0.50 a day.', 'Only fifty cents a day.'];
        yield 'euros after the number' => ['Save 20 EUR a week.', 'Save twenty euros a week.'];
        yield 'pounds' => ['£3.50 for coffee', 'three pounds and fifty pence for coffee'];
        yield 'thousands of dollars' => ['A $10k emergency fund', 'A ten thousand dollars emergency fund'];
        yield 'percent' => ['Put 20% aside, or 2.5% at least.', 'Put twenty percent aside, or two point five percent at least.'];
        yield 'time' => ['Wake up at 7:30 or 6:05, not 9:00.', "Wake up at seven thirty or six oh five, not nine o'clock."];
        yield 'time with pm' => ['Stop at 9pm.', 'Stop at nine p m.'];
        yield 'ordinals' => ['The 1st, 2nd, 3rd and 21st day', 'The first, second, third and twenty-first day'];
        yield 'years' => ['In 2026 and 2005, like the 1990s', 'In twenty twenty-six and two thousand five, like the nineteen nineties'];
        yield 'ranges' => ['Wait 24-48 hours.', 'Wait twenty-four to forty-eight hours.'];
        yield 'numbers' => ['Wait 24 hours, 3.5 times, 1,000,000 people', 'Wait twenty-four hours, three point five times, one million people'];
        yield 'emoji and hashtags' => ['Try it 💪 #money', 'Try it money'];
        yield 'twenty-four seven' => ['Shops are open 24/7 & online.', 'Shops are open twenty-four seven and online.'];
    }

    #[DataProvider('lines')]
    public function test_a_line_reaches_the_voice_as_words(string $written, string $spoken): void
    {
        $this->assertSame($spoken, (new SpokenEnglish)->speak($written));
    }

    public function test_the_brands_pronunciations_come_first(): void
    {
        $this->assertSame('Ask Lee-stoh, it is free.', (new SpokenEnglish)->speak('Ask Listo, it is free.', ['Listo' => 'Lee-stoh']));
    }

    public function test_cardinals_and_ordinals(): void
    {
        $this->assertSame('one hundred five', SpokenEnglish::cardinal(105));
        $this->assertSame('two billion three hundred million', SpokenEnglish::cardinal(2_300_000_000));
        $this->assertSame('twelfth', SpokenEnglish::ordinal(12));
        $this->assertSame('forty-second', SpokenEnglish::ordinal(42));
        $this->assertSame('twentieth', SpokenEnglish::ordinal(20));
        $this->assertSame('nineteen oh five', SpokenEnglish::year(1905));
    }
}
