<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Brend')
                    ->description('Jedan brend = jedna stranica s vlastitim računima, predlošcima i glasom.')
                    ->schema([
                        TextInput::make('name')->label('Naziv')->required()->maxLength(120),
                        TextInput::make('slug')->label('Slug')->required()->alphaDash()->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->helperText('Isti slug mora vraćati i Social Feed (polje brand).'),
                        TextInput::make('site_url')->label('Web adresa')->url()->maxLength(255),
                        Select::make('timezone')->label('Vremenska zona')->required()->default('Europe/Zagreb')
                            ->options(['Europe/Zagreb' => 'Europe/Zagreb', 'UTC' => 'UTC', 'Europe/Berlin' => 'Europe/Berlin']),
                        FileUpload::make('logo_path')->label('Logo')->image()->disk('public')->directory('brands')
                            ->visibility('public')->maxSize(2048)->columnSpanFull(),
                    ])->columns(2),

                Section::make('Boje predložaka')
                    ->description('Koriste ih generički predlošci slika po vrsti sadržaja.')
                    ->schema([
                        ColorPicker::make('colors.primary')->label('Primarna')->default('#1d4ed8'),
                        ColorPicker::make('colors.accent')->label('Naglasak')->default('#f59e0b'),
                        ColorPicker::make('colors.text')->label('Tekst')->default('#111827'),
                        ColorPicker::make('colors.muted')->label('Prigušeni tekst')->default('#6b7280'),
                        ColorPicker::make('colors.background')->label('Pozadina')->default('#ffffff'),
                        ColorPicker::make('colors.surface')->label('Površina')->default('#f3f4f6'),
                    ])->columns(3),

                Section::make('Glas brenda')
                    ->description('Upute AI agentu i fiksni hashtagovi.')
                    ->schema([
                        Textarea::make('voice.tone')->label('Ton')->rows(2)->placeholder('prijateljski, jasan, bez pretjerivanja'),
                        Textarea::make('voice.rules')->label('Pravila')->rows(3)->placeholder('Ne izmišljaj brojke. Uvijek navedi lokaciju. Bez emotikona u naslovu.'),
                        TagsInput::make('voice.hashtags')->label('Fiksni hashtagovi')->placeholder('studentskiposao'),
                        TextInput::make('voice.cta')->label('Poziv na akciju')->placeholder('Prijavi se na studentski-poslovi.hr'),
                        Toggle::make('voice.agent_enabled')->label('AI piše nacrte')
                            ->helperText('Jutarnji prolaz piše tekstove za nove stavke i ostavlja ih na odobrenje. Ne objavljuje.')
                            ->default(false)->columnSpanFull(),
                    ])->columns(2),

                Section::make('Termini objave')
                    ->description('Auto-publish raspoređuje objave unutar ovih prozora (lokalno vrijeme brenda).')
                    ->schema([
                        Repeater::make('posting_windows')->label('')
                            ->schema([
                                TimePicker::make('from')->label('Od')->seconds(false)->required(),
                                TimePicker::make('to')->label('Do')->seconds(false)->required(),
                            ])->columns(2)->default([['from' => '08:00', 'to' => '20:00']])->reorderable(false),
                    ]),
            ]);
    }
}
