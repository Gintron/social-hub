<?php

declare(strict_types=1);

namespace App\Publishing\Exceptions;

final class TransientPublishException extends PublishException
{
    public function __construct(string $message, string $errorCode = 'transient')
    {
        parent::__construct($message, $errorCode, retryable: true, retryAfterSeconds: 120);
    }
}
