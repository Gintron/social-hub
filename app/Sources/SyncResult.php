<?php

declare(strict_types=1);

namespace App\Sources;

final readonly class SyncResult
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $unchanged,
    ) {}

    public function total(): int
    {
        return $this->created + $this->updated + $this->unchanged;
    }
}
