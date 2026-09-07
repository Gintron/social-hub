<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Schemas\CaptionSet;

final readonly class CaptionResult
{
    public function __construct(
        public CaptionSet $captions,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
