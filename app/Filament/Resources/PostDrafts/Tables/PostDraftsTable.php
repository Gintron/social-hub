<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostDrafts\Tables;

use App\Actions\ApproveDraft;
use App\Enums\DraftStatus;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Support\Placeholders;
use App\Filament\Support\PlatformBadges;
use App\Models\PostDraft;
use Filament\Actions\Action;
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
use Livewire\Component;
use Throwable;

final class PostDraftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->emptyStateIcon(Heroicon::OutlinedMegaphone)
            ->emptyStateHeading(fn (Component $livewire): string => match ($livewire->activeTab ?? 'all') {
                'pending' => 'Ništa ne čeka odobrenje',
                'scheduled' => 'Ništa nije zakazano',
                'published' => 'Još ništa nije objavljeno',
                'failed' => 'Nema neuspjelih objava',
                default => 'Još nema objava',
            })
            ->emptyStateDescription(fn (Component $livewire): string => match ($livewire->activeTab ?? 'all') {
                'pending' => 'Nove objave pojave se ovdje čim ih hub ili AI agent pripremi, ili ih sam napraviš iz sadržaja izvora.',
                'scheduled' => 'Odobri ili zakaži objavu i vidjet ćeš je ovdje.',
                'published' => 'Objavljene objave, s pregledima, nalaze se ovdje.',
                'failed' => 'Sve što je hub pokušao objaviti je izašlo. Ako nešto ne uspije, ovdje piše zašto.',
                default => 'Napravi prvu: u sadržaju izvora odaberi stavku i klikni „Napravi objavu“.',
            })
            ->emptyStateActions([
                Action::make('content')->label('Sadržaj iz izvora')->icon(Heroicon::OutlinedNewspaper)->color('gray')
                    ->url(ContentItemResource::getUrl('index')),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['brand', 'variants.media', 'variants.latestMetric', 'mediaAssets', 'contentItems']))
            ->columns([
                ImageColumn::make('preview')->label('')
                    ->state(fn (PostDraft $record): ?string => $record->mediaAssets->first()?->publicUrl())
                    ->defaultImageUrl(Placeholders::thumbnail())
                    ->extraImgAttributes(fn (PostDraft $record): array => ['alt' => $record->mediaAssets->isEmpty() ? 'Medij još nije izrađen' : 'Pregled objave: '.$record->title, 'loading' => 'lazy'])
                    ->square()->size(56)->visibleFrom('md'),
                TextColumn::make('title')->label('Objava')->searchable()->limit(60)->wrap()
                    ->description(fn (PostDraft $record): string => $record->brand?->name.($record->staleItems()->isNotEmpty() ? ' · ⚠ izvor je izmijenjen' : '')),
                TextColumn::make('status')->label('Status')->badge()->sortable(),
                TextColumn::make('reason')->label('Razlog')
                    ->state(fn (PostDraft $record): ?string => $record->variants->first(fn ($variant): bool => filled($variant->error_message))?->error_message)
                    ->limit(70)->tooltip(fn (PostDraft $record): ?string => $record->variants->first(fn ($variant): bool => filled($variant->error_message))?->error_message)
                    // Only where a reason is what the person came for; elsewhere it is a column of dashes.
                    ->placeholder('—')
                    ->visible(fn (Component $livewire): bool => in_array($livewire->activeTab ?? 'all', ['failed', 'all'], true))
                    ->color('danger')->visibleFrom('xl'),
                PlatformBadges::column()->visibleFrom('xl'),
                TextColumn::make('views')->label('Pregledi')
                    ->state(fn (PostDraft $record): ?int => ($views = $record->variants->sum(fn ($variant): int => (int) $variant->latestMetric?->views)) > 0 ? $views : null)
                    ->tooltip(fn (PostDraft $record): ?string => $record->variants
                        ->filter(fn ($variant): bool => $variant->latestMetric !== null)
                        ->map(fn ($variant): string => $variant->platform->label().': '.number_format((int) $variant->latestMetric->views, 0, ',', '.'))
                        ->implode(' · ') ?: null)
                    ->numeric(decimalPlaces: 0, locale: 'hr')
                    ->placeholder('—')
                    ->visibleFrom('xl'),
                TextColumn::make('scheduled_at')->label('Zakazano')->dateTime('d.m.Y H:i', 'Europe/Zagreb')->sortable()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('created_by_type')->label('Autor')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Ažurirano')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label('Brend')->relationship('brand', 'name'),
                SelectFilter::make('status')->label('Status')->options(DraftStatus::class),
            ])
            ->recordActions([
                EditAction::make()->label('Pregledaj')->iconButton()->tooltip('Pregledaj objavu'),
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
