<?php

declare(strict_types=1);

namespace App\Publishing;

final readonly class PublishResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $externalId,
        public ?string $permalink,
        public array $raw = [],
    ) {}
}
