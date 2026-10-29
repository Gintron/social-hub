<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Wording;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The hub's own words: every language says every one of them, so a German brand can never fall back to Croatian.
 */
final class WordingTest extends TestCase
{
    public function test_every_language_has_the_same_words(): void
    {
        $croatian = Wording::keys('hr');
        sort($croatian);

        foreach (Wording::LANGUAGES as $language) {
            $keys = Wording::keys($language);
            sort($keys);
            $this->assertSame($croatian, $keys, "'{$language}' must say exactly what Croatian says.");
        }
    }

    public function test_an_unknown_language_is_croatian(): void
    {
        $this->assertSame('hr', Wording::language(null));
        $this->assertSame('hr', Wording::language(''));
        $this->assertSame('hr', Wording::language('fr'));
        $this->assertSame('de', Wording::language(' DE '));
    }

    public function test_placeholders_are_filled(): void
    {
        $this->assertSame('Top 5 akcija ovog tjedna', Wording::get('hr', 'digest.deal', ['count' => 5]));
        $this->assertSame('Die Top 5 Angebote dieser Woche', Wording::get('de', 'digest.deal', ['count' => 5]));
    }

    public function test_plurals_follow_the_language(): void
    {
        $this->assertSame('ponuda', Wording::plural('hr', 21, 'offers'));
        $this->assertSame('ponude', Wording::plural('hr', 3, 'offers'));
        $this->assertSame('ponuda', Wording::plural('hr', 12, 'offers'));
        $this->assertSame('Angebot', Wording::plural('de', 1, 'offers'));
        $this->assertSame('Angebote', Wording::plural('de', 21, 'offers'));
    }

    public function test_a_missing_key_is_an_error_not_croatian(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Wording::get('de', 'nothing.here');
    }
}
