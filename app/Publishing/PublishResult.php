<?php

declare(strict_types=1);

namespace App\Publishing;

final readonly class PublishResult
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  bool  $handedToCreator  The platform accepted the upload but a human still has to post it
     *                                 (TikTok inbox); the variant waits in ManualPending, not Published.
     */
    public function __construct(
        public string $externalId,
        public ?string $permalink,
        public array $raw = [],
        public bool $handedToCreator = false,
    ) {}
}
