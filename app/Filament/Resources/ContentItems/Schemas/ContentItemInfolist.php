<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentItems\Schemas;

use App\Models\ContentItem;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ContentItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Stavka')
                    ->schema([
                        TextEntry::make('brand.name')->label('Brend'),
                        TextEntry::make('kind')->label('Vrsta')->badge(),
                        TextEntry::make('title')->label('Naslov')->columnSpanFull(),
                        TextEntry::make('subtitle')->label('Podnaslov')->placeholder('—'),
                        TextEntry::make('priority')->label('Prioritet')->placeholder('—'),
                        TextEntry::make('url')->label('URL')->url(fn (ContentItem $record): string => $record->url, shouldOpenInNewTab: true)->columnSpanFull(),
                        TextEntry::make('badges')->label('Oznake')->badge()->color('gray')->placeholder('—'),
                        TextEntry::make('tags')->label('Tagovi')->badge()->color('info')->placeholder('—'),
                        TextEntry::make('published_at')->label('Objavljeno')->dateTime('d.m.Y H:i', 'Europe/Zagreb')->placeholder('—'),
                        TextEntry::make('expires_at')->label('Istječe')->dateTime('d.m.Y H:i', 'Europe/Zagreb')->placeholder('—'),
                        TextEntry::make('source_updated_at')->label('Izmijenjeno na izvoru')->dateTime('d.m.Y H:i', 'Europe/Zagreb'),
                        TextEntry::make('external_id')->label('ID na izvoru'),
                    ])->columns(2),

                Section::make('Činjenice')
                    ->schema([
                        KeyValueEntry::make('facts_map')->label('')
                            ->state(fn (ContentItem $record): array => collect($record->facts ?? [])->mapWithKeys(fn (array $fact): array => [$fact['label'] => $fact['value']])->all())
                            ->keyLabel('Oznaka')->valueLabel('Vrijednost'),
                        KeyValueEntry::make('price')->label('Cijena')->visible(fn (ContentItem $record): bool => filled($record->price)),
                    ]),

                Section::make('Tekst')
                    ->schema([
                        TextEntry::make('body_text')->label('')->placeholder('—')->prose(),
                    ])->collapsible(),

                Section::make('Slike')
                    ->schema([
                        ImageEntry::make('images')->label('')
                            ->state(fn (ContentItem $record): array => array_column($record->images ?? [], 'url'))
                            ->height(160)->stacked(false)->placeholder('Bez slika — predložak koristi emoji i boje brenda.'),
                    ]),

                Section::make('Sirovi payload')
                    ->schema([
                        TextEntry::make('raw_json')->label('')->state(fn (ContentItem $record): string => json_encode($record->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
                            ->fontFamily('mono')->copyable(),
                    ])->collapsed(),
            ]);
    }
}
