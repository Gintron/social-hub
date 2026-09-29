<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * An answer that fitted its schema: the decoded JSON, the model that gave it and what it cost in tokens
 * (thinking included in the output).
 */
final readonly class OpenAiResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
