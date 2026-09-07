<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VariantStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case ManualPending = 'manual_pending';
    case ManualDone = 'manual_done';
    case Skipped = 'skipped';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Čeka',
            self::Queued => 'U redu',
            self::Publishing => 'Objavljuje se',
            self::Published => 'Objavljeno',
            self::Failed => 'Neuspjelo',
            self::ManualPending => 'Čeka ručnu objavu',
            self::ManualDone => 'Ručno objavljeno',
            self::Skipped => 'Preskočeno',
            self::Disabled => 'Isključeno',
        };
    }

    public function isDone(): bool
    {
        return in_array($this, [self::Published, self::ManualDone, self::Skipped, self::Disabled], true);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Disabled, self::Skipped => 'gray',
            self::Queued, self::Publishing, self::ManualPending => 'warning',
            self::Published, self::ManualDone => 'success',
            self::Failed => 'danger',
        };
    }
}
