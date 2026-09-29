<?php

declare(strict_types=1);

namespace App\Voiceover;

/**
 * What a video says, one line per slide.
 *
 * It is kept in its written form — "Kruh za 1,49 €", not "za jedan euro i četrdeset devet centi" —
 * because that is what a person can check against the offer, and what the amount check can read.
 */
final readonly class Script
{
    /**
     * @param  list<ScriptLine>  $lines
     */
    public function __construct(public array $lines) {}

    /**
     * @return list<array{role: string, text: string}>
     */
    public function toArray(): array
    {
        return array_map(fn (ScriptLine $line): array => ['role' => $line->role, 'text' => $line->text], $this->lines);
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return array_map(fn (ScriptLine $line): string => $line->text, $this->lines);
    }

    public function characters(): int
    {
        return array_sum(array_map(fn (ScriptLine $line): int => mb_strlen($line->text), $this->lines));
    }
}
