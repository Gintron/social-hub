<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The first screen: what is waiting for a person, what is going out next, and, in an empty hub,
 * the steps to a first post. Widgets are discovered from app/Filament/Widgets and ordered by their $sort.
 */
final class Dashboard extends BaseDashboard
{
    public function getSubheading(): string|Htmlable|null
    {
        return 'Što čeka tebe, što izlazi sljedeće i radi li sve što bi trebalo.';
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
