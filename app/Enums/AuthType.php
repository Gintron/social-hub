<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AuthType: string implements HasLabel
{
    case Bearer = 'bearer';
    case ApiKey = 'api_key';
    case Hmac = 'hmac';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Bearer => 'Authorization: Bearer <token>',
            self::ApiKey => 'X-Api-Key: <key>',
            self::Hmac => 'HMAC-SHA256 potpis (webhook)',
            self::None => 'Bez autentikacije',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
