<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Support\Enums\Width;

/**
 * Create and edit pages hold one column of sections, so they stop at a width a line of text is
 * comfortable at. The panel itself is full width, which is for tables and the calendar.
 *
 * A method, not a property: a trait cannot redeclare the page's inherited `$maxContentWidth`
 * with another default.
 */
trait ReadableFormWidth
{
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::FiveExtraLarge;
    }
}
