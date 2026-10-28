<?php

declare(strict_types=1);

namespace Tests\Unit\Persona;

use App\Persona\Persona;
use App\Persona\ShortScript;
use InvalidArgumentException;
use Tests\TestCase;

final class PersonaTest extends TestCase
{
    public function test_a_brand_without_a_character_is_none_and_the_defaults_fill_the_rest(): void
    {
        $none = Persona::fromArray(null, 'Listo');

        $this->assertFalse($none->enabled);
        $this->assertFalse($none->isReady());
        $this->assertSame('Listo', $none->name);
        $this->assertSame('en', $none->language);

        $odd = Persona::fromArray(['enabled' => true, 'language' => 'de', 'topics_per_day' => 99, 'suggest_at' => '07:30:00', 'description' => 'x', 'goal' => 'y']);

        $this->assertTrue($odd->isReady());
        $this->assertSame('en', $odd->language);
        $this->assertSame(Persona::MAX_TOPICS_PER_DAY, $odd->topicsPerDay);
        $this->assertSame('07:30', $odd->suggestAt);
        $this->assertSame(Persona::DEFAULT_SUGGEST_AT, Persona::fromArray(['suggest_at' => 'jutro'])->suggestAt);
    }

    public function test_the_brief_leaves_out_what_was_not_filled_in(): void
    {
        $brief = Persona::fromArray(['name' => 'Ana', 'description' => 'Kuharica.', 'goal' => 'Jeftini ručkovi.', 'language' => 'hr'])->brief();

        $this->assertStringContainsString('Name: Ana', $brief);
        $this->assertStringContainsString('Language of everything you write: Hrvatski', $brief);
        $this->assertStringNotContainsString('Never talk about', $brief);
    }

    public function test_a_script_keeps_its_lines_and_estimates_how_long_it_is_spoken(): void
    {
        $script = ShortScript::fromArray([
            'title' => ' Wait a day ',
            'lines' => [
                ['text' => 'one two three four five six seven eight nine ten', 'shot' => 'talking', 'visual' => ''],
                ['text' => '   ', 'shot' => 'broll'],
                ['text' => 'eleven twelve thirteen', 'shot' => 'drone', 'visual' => 'a park'],
            ],
            'caption' => 'x',
        ]);

        $this->assertSame('Wait a day', $script->title);
        $this->assertCount(2, $script->lines);
        $this->assertSame('talking', $script->lines[1]['shot']);
        $this->assertSame(5, $script->estimatedSeconds('en'));
        $this->assertSame('', $script->hashtags);
    }

    public function test_a_script_without_lines_or_title_is_no_script(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ShortScript::fromArray(['title' => '', 'lines' => [['text' => 'Hello']]]);
    }
}
