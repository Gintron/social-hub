<?php

declare(strict_types=1);

namespace App\Filament\Resources\TopicIdeas\Tables;

use App\Enums\TopicStatus;
use App\Filament\Resources\TopicIdeas\TopicIdeaActions;
use App\Models\TopicIdea;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class TopicIdeasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('for_date')->orderBy('id'))
            // Topics arrive and scripts are written in the background; the list shows them when they come.
            ->poll('15s')
            ->emptyStateIcon(Heroicon::OutlinedLightBulb)
            ->emptyStateHeading('Još nema tema')
            ->emptyStateDescription('Teme stižu svako jutro za brendove koji su AI lik (Brendovi → AI lik). Možeš ih tražiti i sada: „Predloži teme“.')
            // The brand is named under the title, so it is loaded with the rows and not once per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('brand'))
            ->columns([
                TextColumn::make('for_date')->label('Dan')->date('d.m.Y')->sortable()->visibleFrom('md'),
                TextColumn::make('title')->label('Tema')->searchable()->wrap()
                    ->description(fn (TopicIdea $record): string => collect([$record->brand->personaSettings()->name, $record->hook])->filter()->implode(' · ')),
                TextColumn::make('status')->label('Stanje')->badge(),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label('Brend')->relationship('brand', 'name'),
                SelectFilter::make('status')->label('Stanje')->options(TopicStatus::class),
                TernaryFilter::make('rejected')->label('Odbačene teme')
                    ->queries(
                        true: fn (Builder $query) => $query->where('status', TopicStatus::Rejected),
                        false: fn (Builder $query) => $query->where('status', '!=', TopicStatus::Rejected),
                        blank: fn (Builder $query) => $query,
                    )->default(false),
            ])
            ->recordActions([
                TopicIdeaActions::choose(),
                TopicIdeaActions::reject(),
                EditAction::make()->label('Otvori')->iconButton()->tooltip('Tema i skripta'),
            ]);
    }
}
