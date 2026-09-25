<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DraftStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartiallyPublished = 'partially_published';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Nacrt',
            self::PendingApproval => 'Čeka odobrenje',
            self::Approved => 'Odobreno',
            self::Scheduled => 'Zakazano',
            self::Publishing => 'Objavljuje se',
            self::Published => 'Objavljeno',
            self::PartiallyPublished => 'Djelomično objavljeno',
            self::Failed => 'Neuspjelo',
            self::Skipped => 'Preskočeno',
            self::Discarded => 'Odbačeno',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Published, self::Discarded, self::Skipped], true);
    }

    public function canBePublished(): bool
    {
        return in_array($this, [self::Approved, self::Scheduled, self::Failed, self::PartiallyPublished], true);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::Discarded, self::Skipped => 'gray',
            self::PendingApproval, self::Publishing, self::PartiallyPublished => 'warning',
            self::Approved, self::Scheduled => 'info',
            self::Published => 'success',
            self::Failed => 'danger',
        };
    }
}
