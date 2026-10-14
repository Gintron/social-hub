<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DraftStatus;
use App\Filament\Pages\PublishingCalendar;
use App\Filament\Resources\PostDrafts\PostDraftResource;
use App\Models\PostDraft;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * What goes out next, soonest first: approved posts with a time still ahead of them.
 */
final class UpcomingPostsTable extends TableWidget
{
    protected static ?int $sort = -9;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Sljedeće objave')
            ->query(fn () => PostDraft::query()
                ->with(['brand', 'variants'])
                ->whereIn('status', [DraftStatus::Approved->value, DraftStatus::Scheduled->value])
                ->where('scheduled_at', '>=', now())
                ->orderBy('scheduled_at')
                ->limit(6))
            ->columns([
                TextColumn::make('scheduled_at')->label('Kad')->dateTime('d.m. H:i', 'Europe/Zagreb'),
                TextColumn::make('title')->label('Objava')->limit(40)
                    ->description(fn (PostDraft $record): string => (string) $record->brand?->name),
            ])
            ->recordUrl(fn (PostDraft $record): string => PostDraftResource::getUrl('edit', ['record' => $record]))
            ->headerActions([
                Action::make('calendar')->label('Kalendar')->icon(Heroicon::OutlinedArrowRight)->link()
                    ->url(PublishingCalendar::getUrl()),
            ])
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedCalendar)
            ->emptyStateHeading('Ništa nije zakazano')
            ->emptyStateDescription('Odobri objavu ili je zakaži, i vidjet ćeš je ovdje.');
    }
}
