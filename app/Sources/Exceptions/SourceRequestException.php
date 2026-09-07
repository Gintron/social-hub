<?php

declare(strict_types=1);

namespace App\Sources\Exceptions;

final class SourceRequestException extends SourceException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?string $url = null)
    {
        parent::__construct($message);
    }
}
