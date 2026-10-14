<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\DraftStatus;
use App\Filament\Resources\PostDrafts\PostDraftResource;
use App\Models\PostDraft;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The oldest posts waiting for a decision, oldest first: the one that has waited longest is the one
 * whose time is closest to passing.
 */
final class PendingApprovalTable extends TableWidget
{
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Čeka tvoje odobrenje')
            ->query(fn () => PostDraft::query()
                ->with(['brand', 'variants'])
                ->where('status', DraftStatus::PendingApproval->value)
                ->oldest('created_at')
                ->limit(6))
            ->columns([
                TextColumn::make('title')->label('Objava')->limit(40)
                    ->description(fn (PostDraft $record): string => (string) $record->brand?->name),
                TextColumn::make('created_at')->label('Čeka')->since(),
            ])
            ->recordUrl(fn (PostDraft $record): string => PostDraftResource::getUrl('edit', ['record' => $record]))
            ->headerActions([
                Action::make('all')->label('Sve za odobrenje')->icon(Heroicon::OutlinedArrowRight)->link()
                    ->url(PostDraftResource::getUrl('index', ['tab' => 'pending'])),
            ])
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading('Ništa ne čeka odobrenje')
            ->emptyStateDescription('Nove objave pojavit će se ovdje čim ih hub ili AI agent pripremi.');
    }
}
