<?php

declare(strict_types=1);

namespace App\Publishing\Exceptions;

use RuntimeException;

/**
 * Base publish failure. `retryable` tells the job whether a later attempt could succeed.
 */
class PublishException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'publish_failed',
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
