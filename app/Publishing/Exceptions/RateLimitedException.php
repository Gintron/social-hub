<?php

declare(strict_types=1);

namespace App\Publishing\Exceptions;

final class RateLimitedException extends PublishException
{
    public function __construct(string $message, int $retryAfterSeconds = 900)
    {
        parent::__construct($message, 'rate_limited', retryable: true, retryAfterSeconds: $retryAfterSeconds);
    }
}
