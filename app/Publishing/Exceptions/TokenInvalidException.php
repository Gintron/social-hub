<?php

declare(strict_types=1);

namespace App\Publishing\Exceptions;

final class TokenInvalidException extends PublishException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'token_invalid', retryable: false);
    }
}
