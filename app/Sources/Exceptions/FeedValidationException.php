<?php

declare(strict_types=1);

namespace App\Sources\Exceptions;

final class FeedValidationException extends SourceException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors, ?string $url = null)
    {
        $where = $url !== null ? " from {$url}" : '';

        parent::__construct(
            'Feed payload'.$where.' does not match Social Feed v1: '.implode('; ', array_slice($errors, 0, 5))
            .(count($errors) > 5 ? ' (+'.(count($errors) - 5).' more)' : '')
        );
    }
}
