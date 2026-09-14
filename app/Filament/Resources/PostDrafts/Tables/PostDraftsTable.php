<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostDrafts\Tables;

use App\Actions\ApproveDraft;
use App\Enums\DraftStatus;
use App\Models\PostDraft;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final class PostDraftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['brand', 'variants.media', 'variants.latestMetric', 'mediaAssets', 'contentItems']))
            ->columns([
                ImageColumn::make('preview')->label('')
                    ->state(fn (PostDraft $record): ?string => $record->mediaAssets->first()?->publicUrl())
                    ->square()->size(56),
                TextColumn::make('brand.name')->label('Brend')->sortable(),
                TextColumn::make('title')->label('Objava')->searchable()->limit(60)
                    ->description(fn (PostDraft $record): ?string => $record->staleItems()->isNotEmpty() ? '⚠ izvor je izmijenjen' : null),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
                TextColumn::make('variants.platform')->label('Kanali')->badge(),
                TextColumn::make('views')->label('Pregledi')
                    ->state(fn (PostDraft $record): ?int => ($views = $record->variants->sum(fn ($variant): int => (int) $variant->latestMetric?->views)) > 0 ? $views : null)
                    ->tooltip(fn (PostDraft $record): ?string => $record->variants
                        ->filter(fn ($variant): bool => $variant->latestMetric !== null)
                        ->map(fn ($variant): string => $variant->platform->label().': '.number_format((int) $variant->latestMetric->views, 0, ',', '.'))
                        ->implode(' · ') ?: null)
                    ->numeric(decimalPlaces: 0, locale: 'hr')
                    ->placeholder('—'),
                TextColumn::make('scheduled_at')->label('Zakazano')->dateTime('d.m.Y H:i', 'Europe/Zagreb')->sortable()->placeholder('—'),
                TextColumn::make('created_by_type')->label('Autor')->badge()->color('gray'),
                TextColumn::make('updated_at')->label('Ažurirano')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label('Brend')->relationship('brand', 'name'),
                SelectFilter::make('status')->label('Status')->options(DraftStatus::class),
            ])
            ->recordActions([
                EditAction::make()->label('Pregledaj'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')
                        ->label('Odobri odabrano')
                        ->icon(Heroicon::OutlinedCheck)
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $approved = 0;
                            $skipped = [];

                            foreach ($records as $draft) {
                                try {
                                    app(ApproveDraft::class)->execute($draft, auth()->id());
                                    $approved++;
                                } catch (Throwable $e) {
                                    $skipped[] = "#{$draft->id}: {$e->getMessage()}";
                                }
                            }

                            Notification::make()
                                ->title("Odobreno: {$approved}")
                                ->body($skipped === [] ? null : "Preskočeno:\n".implode("\n", $skipped))
                                ->status($skipped === [] ? 'success' : 'warning')
                                ->send();
                        }),
                ]),
            ]);
    }
}
