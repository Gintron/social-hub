<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Platform;
use Filament\Tables\Columns\TextColumn;

/**
 * The channels a post goes to, as one row of short badges. A draft has up to five, so the full
 * names ("Facebook grupa (ručno)") push the rest of a table off the screen.
 */
final class PlatformBadges
{
    public static function column(string $name = 'variants.platform'): TextColumn
    {
        return TextColumn::make($name)
            ->label('Kanali')
            ->badge()
            ->formatStateUsing(fn (Platform|string $state): string => ($state instanceof Platform ? $state : Platform::from($state))->shortLabel());
    }
}
