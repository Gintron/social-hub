<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentItems\RelationManagers;

use App\Filament\Resources\PostDrafts\PostDraftResource;
use App\Filament\Support\PlatformBadges;
use App\Models\PostDraft;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What became of this item: the posts that were made from it, so "was this one posted?" is answered
 * on the item and not by searching the list of posts.
 */
final class PostDraftsRelationManager extends RelationManager
{
    protected static string $relationship = 'postDrafts';

    protected static ?string $title = 'Objave iz ove stavke';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('variants'))
            ->defaultSort('post_drafts.created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Objava')->limit(50),
                TextColumn::make('status')->label('Status')->badge(),
                PlatformBadges::column()->visibleFrom('md'),
                TextColumn::make('scheduled_at')->label('Termin')->dateTime('d.m.Y H:i', 'Europe/Zagreb')->placeholder('—')->visibleFrom('md'),
            ])
            ->recordUrl(fn (PostDraft $record): string => PostDraftResource::getUrl('edit', ['record' => $record]))
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedMegaphone)
            ->emptyStateHeading('Iz ove stavke još nije napravljena objava')
            ->emptyStateDescription('Klikni „Napravi objavu“ gore desno.');
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
