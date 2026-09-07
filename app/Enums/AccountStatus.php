<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AccountStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case NeedsReconnect = 'needs_reconnect';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktivan',
            self::NeedsReconnect => 'Treba ponovno povezati',
            self::Disabled => 'Isključen',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::NeedsReconnect => 'danger',
            self::Disabled => 'gray',
        };
    }
}
