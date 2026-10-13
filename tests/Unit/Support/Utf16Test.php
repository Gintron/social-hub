<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Utf16;
use Tests\TestCase;

final class Utf16Test extends TestCase
{
    public function test_an_emoji_costs_two_units_and_a_croatian_letter_one(): void
    {
        $this->assertSame(0, Utf16::length(''));
        $this->assertSame(1, Utf16::length('č'));
        $this->assertSame(2, Utf16::length('🚀'));
        $this->assertSame(5, Utf16::length('ćčš🚀'));
    }

    public function test_a_cut_never_splits_a_character(): void
    {
        // 'a' is 1 unit, the rocket 2, 'b' 1. Cutting to 2 keeps 'a' and drops the rocket whole.
        $this->assertSame('a', Utf16::cut('a🚀b', 2));
        $this->assertSame('a🚀', Utf16::cut('a🚀b', 3));
        $this->assertSame('a🚀b', Utf16::cut('a🚀b', 10));
    }
}
