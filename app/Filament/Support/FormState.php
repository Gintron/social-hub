<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Platform;

final class FormState
{
    /**
     * Repeater/relationship state may carry the enum instance or its backing value; accept both.
     */
    public static function platform(mixed $value): ?Platform
    {
        if ($value instanceof Platform) {
            return $value;
        }

        return is_string($value) ? Platform::tryFrom($value) : null;
    }
}
