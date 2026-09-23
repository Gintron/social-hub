<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sources\Schemas;

use App\Enums\AuthType;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Enums\SourceType;
use App\Models\Source;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class SourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Izvor')
                    ->description('Odakle hub povlači stavke. Za novu stranicu: implementiraj Social Feed v1 (docs/social-feed-v1.md), zalijepi URL i token, klikni "Testiraj vezu".')
                    ->schema([
                        Select::make('brand_id')->label('Brend')->relationship('brand', 'name')->required()->searchable()->preload(),
                        TextInput::make('name')->label('Naziv')->required()->maxLength(120)->placeholder('studentski-poslovi'),
                        Select::make('type')->label('Vrsta')->options(SourceType::class)->default(SourceType::SocialFeedV1)->required()->live(),
                        Select::make('auth_type')->label('Autentikacija')->options(AuthType::class)->default(AuthType::Bearer)->required(),
                        TextInput::make('base_url')->label('URL feeda')->url()->maxLength(2000)->columnSpanFull()
                            ->placeholder('https://studentski-poslovi.hr/api/social-feed')
                            ->visible(fn (Get $get): bool => $get('type') !== SourceType::Webhook->value && $get('type') !== SourceType::Webhook),
                        TextInput::make('secret')->label('Token / ključ / HMAC tajna')->password()->revealable()->maxLength(4000)->columnSpanFull()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Sprema se šifrirano. Ostavi prazno da zadržiš postojeću vrijednost.'),
                        Toggle::make('enabled')->label('Uključen')->default(true),
                        DateTimePicker::make('sync_since')->label('Prva sinkronizacija od')->timezone('Europe/Zagreb')->seconds(false)
                            ->helperText('Prazno = povuci sve što feed vrati.'),
                    ])->columns(2),

                Section::make('Napredno')
                    ->schema([
                        KeyValue::make('config')->label('Postavke')->keyLabel('Ključ')->valueLabel('Vrijednost')
                            ->helperText('npr. page_limit = 50'),
                    ])->collapsed(),

                Section::make('Automatska objava')
                    ->description('Zadano je isključeno: nove stavke čekaju pregled. Uključi po platformi tek kad si zadovoljan kvalitetom nacrta — tada hub sam radi objavu i zakazuje je u sljedeći termin brenda.')
                    ->schema([
                        Repeater::make('autoPublishRules')
                            ->label('')
                            ->relationship()
                            ->addActionLabel('Dodaj pravilo')
                            ->schema([
                                Select::make('platform')->label('Platforma')
                                    ->options(collect(Platform::cases())
                                        ->mapWithKeys(fn (Platform $platform): array => [$platform->value => $platform->label()])
                                        ->all())
                                    ->required()
                                    ->distinct()
                                    ->live()
                                    ->helperText(fn (Get $get): ?string => match (self::platform($get)) {
                                        Platform::FacebookGroup => 'Ručni kanal: hub pripremi objavu, čovjek je zalijepi u grupu.',
                                        Platform::TikTok => config('tiktok.driver') === 'business'
                                            ? 'Izravna objava je javna; inbox samo ako čovjek dodaje trending zvuk u aplikaciji.'
                                            : 'Dok TikTok ne auditira aplikaciju, izravna objava je privatna — koristi inbox.',
                                        default => null,
                                    }),
                                Select::make('format')->label('Format')
                                    ->options(fn (Get $get): array => collect(self::platform($get)?->formats() ?? [])
                                        ->mapWithKeys(fn (ContentFormat $format): array => [$format->value => $format->label()])
                                        ->all())
                                    ->placeholder('Zadano za platformu'),
                                Toggle::make('enabled')->label('Uključeno')->default(false),
                                TextInput::make('delay_minutes')->label('Odgoda (min)')->numeric()->default(0)->minValue(0)->maxValue(10080)
                                    ->helperText('Koliko čekati nakon što stavka stigne.'),
                                TextInput::make('daily_cap')->label('Najviše dnevno')->numeric()->minValue(1)->maxValue(50)
                                    ->helperText('Za ovaj kanal; najviši prioritet ide prvi. Prazno = do 25.'),
                                Select::make('settings.delivery')->label('Isporuka')
                                    ->options([
                                        'direct' => 'Objavi izravno',
                                        'inbox' => 'Pošalji u TikTok inbox (trending zvuk i objava u aplikaciji)',
                                    ])
                                    ->default('inbox')
                                    ->visible(fn (Get $get): bool => self::platform($get) === Platform::TikTok),
                                Toggle::make('settings.auto_add_music')->label('TikTok dodaje glazbu foto objavi')->default(true)
                                    ->visible(fn (Get $get): bool => self::platform($get) === Platform::TikTok),
                                Toggle::make('settings.share_to_feed')->label('Reel i u feed profila')->default(true)
                                    ->visible(fn (Get $get): bool => self::platform($get) === Platform::InstagramBusiness),
                            ])
                            ->columns(3)
                            ->defaultItems(0),
                        Toggle::make('auto_publish_backlog')->label('Objavi i ono što je dnevni limit zadržao')
                            ->helperText('Svako jutro (06:30) rasporedi aktivne stavke koje još nisu objavljene, do dnevnog limita. Bez toga stavka koja stigne preko limita nikad ne ide van.'),
                    ])
                    ->collapsed()
                    ->visibleOn('edit'),

                Section::make('Stanje')
                    ->schema([
                        Placeholder::make('last_synced')->label('Zadnja sinkronizacija')
                            ->content(fn (?Source $record): string => $record?->last_synced_at?->timezone('Europe/Zagreb')->format('d.m.Y H:i') ?? '—'),
                        Placeholder::make('items')->label('Stavki u hubu')
                            ->content(fn (?Source $record): string => (string) ($record?->contentItems()->count() ?? 0)),
                        Placeholder::make('error')->label('Zadnja greška')
                            ->content(fn (?Source $record): string => $record?->last_error ?? '—')->columnSpanFull(),
                    ])->columns(2)->visibleOn('edit'),
            ]);
    }

    private static function platform(Get $get): ?Platform
    {
        $platform = $get('platform');

        return $platform instanceof Platform ? $platform : Platform::tryFrom((string) $platform);
    }
}
