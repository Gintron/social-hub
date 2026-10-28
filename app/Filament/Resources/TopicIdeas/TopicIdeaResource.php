<?php

declare(strict_types=1);

namespace App\Filament\Resources\TopicIdeas;

use App\Filament\Resources\TopicIdeas\Pages\EditTopicIdea;
use App\Filament\Resources\TopicIdeas\Pages\ListTopicIdeas;
use App\Filament\Resources\TopicIdeas\Schemas\TopicIdeaForm;
use App\Filament\Resources\TopicIdeas\Tables\TopicIdeasTable;
use App\Models\Brand;
use App\Models\TopicIdea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The topics AI characters could talk about today, and the scripts of the ones a person picked. Topics come from the
 * model (hub:suggest-topics); a person picks, reads and approves here.
 */
final class TopicIdeaResource extends Resource
{
    protected static ?string $model = TopicIdea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Sadržaj';

    protected static ?string $navigationLabel = 'Teme AI likova';

    protected static ?string $modelLabel = 'tema';

    protected static ?string $pluralModelLabel = 'teme';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 3;

    /**
     * Only where a brand is a character: for every other brand the menu stays as it was.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return once(fn (): bool => Brand::query()->whereNotNull('persona')->get()
            ->contains(fn (Brand $brand): bool => $brand->personaSettings()->enabled));
    }

    public static function form(Schema $schema): Schema
    {
        return TopicIdeaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TopicIdeasTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTopicIdeas::route('/'),
            'edit' => EditTopicIdea::route('/{record}/edit'),
        ];
    }
}
