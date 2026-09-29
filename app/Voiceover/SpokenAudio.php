<?php

declare(strict_types=1);

namespace App\Voiceover;

/**
 * What one text-to-speech request returned.
 */
final readonly class SpokenAudio
{
    public function __construct(
        public string $bytes,
        public ?string $requestId,
        /** What ElevenLabs charged for it (the `character-cost` header), else the length of the text. */
        public int $characters,
    ) {}
}
