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
        /**
         * What ElevenLabs billed for it: the `character-cost` header, null when it sent none. Despite the name it
         * is not the length of the text but the account's billing units, and they depend on the model.
         */
        public ?int $cost,
    ) {}
}
