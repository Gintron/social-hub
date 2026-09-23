<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContentKind: string implements HasColor, HasLabel
{
    case Job = 'job';
    case Deal = 'deal';
    case Article = 'article';
    case Event = 'event';
    case Generic = 'generic';
    // Rang ponuda više izvora (lanaca, poslodavaca) po jednoj brojci: `facts` su redci, od prvog.
    case Comparison = 'comparison';

    public function label(): string
    {
        return match ($this) {
            self::Job => 'Oglas za posao',
            self::Deal => 'Akcija / proizvod',
            self::Article => 'Članak',
            self::Event => 'Događaj',
            self::Generic => 'Općenito',
            self::Comparison => 'Usporedba',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Job => 'info',
            self::Deal => 'success',
            self::Article => 'warning',
            self::Event => 'primary',
            self::Generic => 'gray',
            self::Comparison => 'danger',
        };
    }
}
