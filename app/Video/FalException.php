<?php

declare(strict_types=1);

namespace App\Video;

use RuntimeException;
use Throwable;

/**
 * fal did not give a video the hub can use. `errorCode` is stable — `not_configured`, `invalid_key`, `rejected`,
 * `failed`, `timeout`, `unavailable`, `error` — and `retryable` says whether asking again later can help.
 */
final class FalException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'error',
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
