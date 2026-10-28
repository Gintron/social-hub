<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a topic of an AI character stands: proposed by the model, picked by a person and written into a script, the
 * script read and approved. Nothing is paid for beyond text until a script is approved.
 */
enum TopicStatus: string implements HasColor, HasLabel
{
    case Proposed = 'proposed';
    case Writing = 'writing';
    case Scripted = 'scripted';
    case Approved = 'approved';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Prijedlog',
            self::Writing => 'Piše se skripta',
            self::Scripted => 'Skripta čeka pregled',
            self::Approved => 'Skripta odobrena',
            self::Failed => 'Skripta nije uspjela',
            self::Rejected => 'Odbačeno',
        };
    }

    /**
     * A person may pick it (again): a proposal, one they set aside, or one whose script did not come.
     */
    public function canBeChosen(): bool
    {
        return in_array($this, [self::Proposed, self::Rejected, self::Failed], true);
    }

    public function hasScript(): bool
    {
        return in_array($this, [self::Scripted, self::Approved], true);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposed => 'gray',
            self::Writing, self::Scripted => 'warning',
            self::Approved => 'success',
            self::Failed => 'danger',
            self::Rejected => 'gray',
        };
    }
}
