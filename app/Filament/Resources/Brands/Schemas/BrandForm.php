<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Schemas;

use App\Actions\ScheduleDigest;
use App\Drafting\DigestBuilder;
use App\Enums\ContentKind;
use Filament\Forms\Components\CheckboxList;
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

                Section::make('Zvuk za video')
                    ->description('Podloge koje hub umiksa ispod TikTok videa i Reelsa. TikTokov API ne može dodati zvuk iz TikTokove knjižnice, pa ovdje idu samo pjesme za koje brend ima prava (royalty-free ili licencirane) — poslovni računi ne smiju koristiti komercijalnu glazbu bez licence. Za trending zvuk pošalji TikTok varijantu u inbox.')
                    ->schema([
                        Repeater::make('audio_tracks')->label('')
                            ->schema([
                                TextInput::make('title')->label('Naziv')->required()->maxLength(120),
                                TextInput::make('license')->label('Izvor / licenca')->maxLength(255)
                                    ->placeholder('npr. Pixabay Music, Content License'),
                                FileUpload::make('path')->label('Datoteka (MP3, M4A, WAV, do 12 MB)')->required()
                                    ->disk('public')->directory('brands/audio')
                                    ->acceptedFileTypes(['audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav'])
                                    // Livewire's temporary upload refuses anything above 12 MB before this rule runs.
                                    ->maxSize(12288)->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->default([])
                            ->addActionLabel('Dodaj pjesmu')
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->collapsible(),
                    ])
                    ->collapsible(),

                Section::make('Pregled tjedna')
                    ->description('Carousel s najboljim aktivnim stavkama u odabrane dane. Ide na kanale kojima je uključena automatska objava (bez ručnih FB grupa), s njihovim postavkama. Stavka smije u pregled i kad je već imala svoju objavu, ali ne dvaput u 7 dana.')
                    ->schema([
                        Toggle::make('digest.enabled')->label('Uključeno')->default(false)->columnSpanFull(),
                        CheckboxList::make('digest.days')->label('Dani')
                            ->options([1 => 'pon', 2 => 'uto', 3 => 'sri', 4 => 'čet', 5 => 'pet', 6 => 'sub', 7 => 'ned'])
                            ->default([1, 4])->columns(7)->columnSpanFull(),
                        TimePicker::make('digest.time')->label('Vrijeme objave')->seconds(false)->default(ScheduleDigest::DEFAULT_TIME),
                        TextInput::make('digest.count')->label('Broj stavki')->numeric()->minValue(2)->maxValue(DigestBuilder::MAX_ITEMS)
                            ->default(ScheduleDigest::DEFAULT_COUNT),
                        Select::make('digest.kind')->label('Vrsta stavki')->options(ContentKind::class)->default(ContentKind::Job->value),
                    ])
                    ->columns(3)
                    ->collapsible(),

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
