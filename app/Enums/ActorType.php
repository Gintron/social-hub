<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ActorType: string implements HasLabel
{
    case Human = 'human';
    case Agent = 'agent';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Human => 'Čovjek',
            self::Agent => 'AI agent',
            self::System => 'Sustav',
        };
    }
}
