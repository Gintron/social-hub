<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostDrafts;

use App\Enums\DraftStatus;
use App\Filament\Resources\PostDrafts\Pages\EditPostDraft;
use App\Filament\Resources\PostDrafts\Pages\ListPostDrafts;
use App\Filament\Resources\PostDrafts\RelationManagers\PublishLogsRelationManager;
use App\Filament\Resources\PostDrafts\Schemas\PostDraftForm;
use App\Filament\Resources\PostDrafts\Tables\PostDraftsTable;
use App\Models\PostDraft;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class PostDraftResource extends Resource
{
    protected static ?string $model = PostDraft::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Sadržaj';

    protected static ?string $navigationLabel = 'Objave';

    protected static ?string $modelLabel = 'objava';

    protected static ?string $pluralModelLabel = 'objave';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return PostDraftForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PostDraftsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PublishLogsRelationManager::class,
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = PostDraft::query()->where('status', DraftStatus::PendingApproval->value)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPostDrafts::route('/'),
            'edit' => EditPostDraft::route('/{record}/edit'),
        ];
    }
}
