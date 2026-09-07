<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentItems\Tables;

use App\Enums\ContentKind;
use App\Filament\Resources\ContentItems\ContentItemActions;
use App\Models\ContentItem;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ContentItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                ImageColumn::make('image')->label('')->state(fn (ContentItem $record): ?string => $record->imageUrl('primary'))->square()->size(48),
                TextColumn::make('brand.name')->label('Brend')->sortable(),
                TextColumn::make('kind')->label('Vrsta')->badge(),
                TextColumn::make('title')->label('Naslov')->searchable()->limit(60)->description(fn (ContentItem $record): ?string => $record->subtitle),
                TextColumn::make('priority')->label('Prioritet')->sortable()->alignCenter(),
                TextColumn::make('badges')->label('Oznake')->badge()->color('gray')->limitList(3),
                TextColumn::make('post_drafts_count')->label('Objave')->counts('postDrafts')->alignCenter()
                    ->badge()->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('expires_at')->label('Istječe')->date('d.m.Y', 'Europe/Zagreb')->sortable()->placeholder('—')
                    ->color(fn (ContentItem $record): ?string => $record->isExpired() ? 'danger' : null),
                TextColumn::make('last_seen_at')->label('Viđeno')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label('Brend')->relationship('brand', 'name'),
                SelectFilter::make('kind')->label('Vrsta')->options(ContentKind::class),
                TernaryFilter::make('live')->label('Aktivno')
                    ->queries(
                        true: fn (Builder $query) => $query->live(),
                        false: fn (Builder $query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()),
                        blank: fn (Builder $query) => $query,
                    )->default(true),
                TernaryFilter::make('drafted')->label('Već u objavi')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('postDrafts'),
                        false: fn (Builder $query) => $query->whereDoesntHave('postDrafts'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                ContentItemActions::createDraft(),
                ViewAction::make()->label('Detalji'),
            ]);
    }
}
