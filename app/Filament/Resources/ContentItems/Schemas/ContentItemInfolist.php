<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentItems\Schemas;

use App\Drafting\Highlights;
use App\Models\ContentItem;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One item as the sources sent it: what a post is made from. One column, in the order a person asks
 * the questions: what is it, what does it cost, what does it look like, what was said about it.
 */
final class ContentItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Stavka')
                    ->schema([
                        TextEntry::make('brand.name')->label('Brend'),
                        TextEntry::make('kind')->label('Vrsta')->badge(),
                        TextEntry::make('title')->label('Naslov')->columnSpanFull(),
                        TextEntry::make('subtitle')->label('Podnaslov')->placeholder('—'),
                        TextEntry::make('priority')->label('Prioritet')->placeholder('—'),
                        TextEntry::make('url')->label('Adresa na stranici')
                            ->url(fn (ContentItem $record): string => $record->url, shouldOpenInNewTab: true)
                            ->icon('heroicon-m-arrow-top-right-on-square')->iconPosition('after')
                            ->columnSpanFull(),
                        TextEntry::make('badges')->label('Oznake')->badge()->color('gray')->placeholder('—'),
                        TextEntry::make('tags')->label('Tagovi')->badge()->color('info')->placeholder('—'),
                        TextEntry::make('published_at')->label('Objavljeno na stranici')->dateTime('d.m.Y H:i', 'Europe/Zagreb')->placeholder('—'),
                        TextEntry::make('expires_at')->label('Istječe')->dateTime('d.m.Y H:i', 'Europe/Zagreb')->placeholder('—'),
                        TextEntry::make('source_updated_at')->label('Zadnja izmjena na izvoru')->dateTime('d.m.Y H:i', 'Europe/Zagreb')->placeholder('—'),
                        TextEntry::make('external_id')->label('Oznaka na izvoru')->fontFamily('mono'),
                    ])->columns(2),

                Section::make('Cijena i činjenice')
                    ->description('Točno ono što će reći slika i tekst objave: iznosi se ne smiju razlikovati od ovih.')
                    ->schema([
                        TextEntry::make('price_line')->label('Cijena')
                            ->state(fn (ContentItem $record): ?string => self::priceLine($record))
                            ->visible(fn (ContentItem $record): bool => self::priceLine($record) !== null),
                        KeyValueEntry::make('facts_map')->hiddenLabel()
                            ->state(fn (ContentItem $record): array => collect($record->facts ?? [])->mapWithKeys(fn (array $fact): array => [$fact['label'] => $fact['value']])->all())
                            ->keyLabel('Činjenica')->valueLabel('Vrijednost')
                            ->visible(fn (ContentItem $record): bool => filled($record->facts)),
                    ])
                    ->visible(fn (ContentItem $record): bool => self::priceLine($record) !== null || filled($record->facts)),

                Section::make('Slike')
                    ->schema([
                        ImageEntry::make('images')->hiddenLabel()
                            ->state(fn (ContentItem $record): array => array_column($record->images ?? [], 'url'))
                            ->height(160)->stacked(false)
                            ->extraImgAttributes(fn (ContentItem $record): array => ['alt' => 'Slika uz stavku: '.$record->title, 'loading' => 'lazy'])
                            ->placeholder('Izvor nije poslao slike: predložak koristi emoji i boje brenda.'),
                    ]),

                Section::make('Tekst')
                    ->schema([
                        TextEntry::make('body_text')->hiddenLabel()->placeholder('Izvor nije poslao tekst.')->prose(),
                    ])->collapsible(),

                Section::make('Sirovi podaci')
                    ->description('Točno ono što je izvor poslao. Za provjeru kad nešto ne izgleda kako treba.')
                    ->schema([
                        TextEntry::make('raw_json')->hiddenLabel()->state(fn (ContentItem $record): string => json_encode($record->raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
                            ->fontFamily('mono')->copyable(),
                    ])->collapsed(),
            ]);
    }

    /**
     * "SATNICA: 7.00 €/H", or "1,49 € (prije 1,99 €) · −25 %": the price as a slide and a caption say it.
     */
    private static function priceLine(ContentItem $item): ?string
    {
        $figure = Highlights::figure($item);

        if ($figure === null) {
            return null;
        }

        $line = $figure['value'].($figure['old'] !== null ? " (prije {$figure['old']})" : '');

        // The label is the site's own name for the figure ("SATNICA") or the discount ("−25 %").
        return match (true) {
            $figure['label'] === null => $line,
            $figure['fact'] !== null => "{$figure['label']}: {$line}",
            default => "{$line} · {$figure['label']}",
        };
    }
}
