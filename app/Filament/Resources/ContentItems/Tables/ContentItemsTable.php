<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentItems\Tables;

use App\Enums\ContentKind;
use App\Filament\Resources\ContentItems\ContentItemActions;
use App\Filament\Resources\Sources\SourceResource;
use App\Filament\Support\Placeholders;
use App\Models\ContentItem;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
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
            ->emptyStateIcon(Heroicon::OutlinedNewspaper)
            ->emptyStateHeading('Nema stavki za ovaj prikaz')
            ->emptyStateDescription('Promijeni filtere ili pretragu. Ako izvor još nije povezan, dodaj ga: bez izvora hub nema iz čega napraviti objavu.')
            ->emptyStateActions([
                Action::make('sources')->label('Izvori sadržaja')->icon(Heroicon::OutlinedRss)->color('gray')
                    ->url(SourceResource::getUrl('index')),
            ])
            // The brand is named under the title, so it is loaded with the rows and not once per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('brand'))
            ->columns([
                ImageColumn::make('image')->label('')->state(fn (ContentItem $record): ?string => $record->imageUrl('primary'))
                    ->defaultImageUrl(Placeholders::thumbnail())
                    ->extraImgAttributes(fn (ContentItem $record): array => ['alt' => $record->imageUrl('primary') === null ? 'Izvor nije poslao sliku' : 'Slika uz stavku: '.$record->title, 'loading' => 'lazy'])
                    ->square()->size(48)->visibleFrom('md'),
                TextColumn::make('kind')->label('Vrsta')->badge()->visibleFrom('md'),
                TextColumn::make('title')->label('Naslov')->searchable()->limit(60)->wrap()->description(fn (ContentItem $record): string => collect([$record->brand?->name, $record->subtitle])->filter()->implode(' · ')),
                TextColumn::make('priority')->label('Prioritet')->sortable()->alignCenter()->visibleFrom('xl'),
                TextColumn::make('badges')->label('Oznake')->badge()->color('gray')->limitList(3)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('post_drafts_count')->label('Objave')->counts('postDrafts')->alignCenter()
                    ->badge()->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')->visibleFrom('xl'),
                TextColumn::make('expires_at')->label('Istječe')->date('d.m.Y', 'Europe/Zagreb')->sortable()->placeholder('—')
                    ->color(fn (ContentItem $record): ?string => $record->isExpired() ? 'danger' : null)->visibleFrom('md'),
                TextColumn::make('last_seen_at')->label('Viđeno')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
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
                ViewAction::make()->label('Detalji')->iconButton()->tooltip('Detalji stavke'),
            ]);
    }
}
