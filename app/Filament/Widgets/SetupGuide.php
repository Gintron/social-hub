<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Support\HubStatus;
use Filament\Widgets\Widget;

/**
 * The way from an empty hub to a first post. It is there until the four steps are done and then it is gone.
 */
final class SetupGuide extends Widget
{
    protected static ?int $sort = -30;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.setup-guide';

    public static function canView(): bool
    {
        return ! app(HubStatus::class)->setupComplete();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $steps = app(HubStatus::class)->setupSteps();
        $next = collect($steps)->search(fn (array $step): bool => ! $step['done']);

        return [
            'steps' => $steps,
            'next' => $next === false ? null : $next,
            'doneCount' => count(array_filter($steps, fn (array $step): bool => $step['done'])),
        ];
    }
}
