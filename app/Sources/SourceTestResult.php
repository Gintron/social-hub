<?php

declare(strict_types=1);

namespace App\Sources;

/**
 * Outcome of "Test connection" for a source: what came back and what looks off.
 */
final readonly class SourceTestResult
{
    /**
     * @param  list<ContentItemData>  $samples
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $brand,
        public int $itemCount,
        public array $samples,
        public array $warnings,
        public ?string $nextCursor,
    ) {}

    public function ok(): bool
    {
        return $this->warnings === [];
    }
}
