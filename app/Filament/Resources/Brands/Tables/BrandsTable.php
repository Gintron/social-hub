<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Tables;

use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Support\Placeholders;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->emptyStateIcon(Heroicon::OutlinedSwatch)
            ->emptyStateHeading('Još nema brendova')
            ->emptyStateDescription('Brend je jedna stranica ili marka s vlastitim bojama, tekstovima, računima i terminima objave. Dodaj prvi.')
            ->emptyStateActions([
                Action::make('create')->label('Dodaj brend')->icon(Heroicon::OutlinedPlus)
                    ->url(BrandResource::getUrl('create')),
            ])
            ->columns([
                ImageColumn::make('logo_path')->label('')->disk('public')->circular()
                    ->defaultImageUrl(Placeholders::thumbnail())
                    ->extraImgAttributes(fn ($record): array => ['alt' => filled($record->logo_path) ? 'Logo: '.$record->name : 'Brend nema logo']),
                TextColumn::make('name')->label('Brend')->searchable()->sortable()->description(fn ($record): string => $record->slug),
                TextColumn::make('site_url')->label('Web')->url(fn ($record): ?string => $record->site_url, shouldOpenInNewTab: true)->visibleFrom('lg'),
                TextColumn::make('sources_count')->label('Izvori')->counts('sources')->visibleFrom('md'),
                TextColumn::make('social_accounts_count')->label('Računi')->counts('socialAccounts')->visibleFrom('md'),
                TextColumn::make('content_items_count')->label('Stavke')->counts('contentItems')->visibleFrom('xl'),
                TextColumn::make('post_drafts_count')->label('Objave')->counts('postDrafts')->visibleFrom('xl'),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip('Uredi brend'),
            ]);
    }
}
