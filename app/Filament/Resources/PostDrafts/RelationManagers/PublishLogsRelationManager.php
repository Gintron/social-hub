<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostDrafts\RelationManagers;

use App\Models\PublishLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PublishLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'publishLogs';

    protected static ?string $title = 'Zapisi objavljivanja';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('publish_logs.created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Vrijeme')->dateTime('d.m.Y H:i:s', 'Europe/Zagreb'),
                TextColumn::make('variant.platform')->label('Kanal')->badge(),
                TextColumn::make('event')->label('Događaj')->badge()->color('gray'),
                TextColumn::make('http_status')->label('HTTP')->badge()
                    ->color(fn (?int $state): string => $state !== null && $state < 300 ? 'success' : 'danger')->placeholder('—'),
                TextColumn::make('response')->label('Odgovor')
                    ->state(fn (PublishLog $record): string => json_encode($record->response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                    ->limit(80)->tooltip(fn (PublishLog $record): string => json_encode($record->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                    ->fontFamily('mono'),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated([10, 25]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
