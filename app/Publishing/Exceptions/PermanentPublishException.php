<?php

declare(strict_types=1);

namespace App\Publishing\Exceptions;

final class PermanentPublishException extends PublishException
{
    public function __construct(string $message, string $errorCode = 'permanent')
    {
        parent::__construct($message, $errorCode, retryable: false);
    }
}
