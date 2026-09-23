<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Schemas;

use App\Drafting\DigestBuilder;
use App\Drafting\DigestSeries;
use App\Enums\ContentKind;
use App\Rendering\VideoRenderer;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

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
                        Select::make('colors.logo_footer')->label('Logo na traci primarne boje')
                            ->options(['white' => 'Bijeli obris', 'original' => 'Originalne boje'])
                            ->default('white')->selectablePlaceholder(false)
                            ->helperText('Originalne boje za logo koji je ispunjen lik (npr. kvadrat) — bijeli obris bi ga pretvorio u bijelu mrlju.'),
                    ])->columns(3),

                Section::make('Glas brenda')
                    ->description('Upute AI agentu i fiksni hashtagovi.')
                    ->schema([
                        Textarea::make('voice.tone')->label('Ton')->rows(2)->placeholder('prijateljski, jasan, bez pretjerivanja'),
                        Textarea::make('voice.rules')->label('Pravila')->rows(3)->placeholder('Ne izmišljaj brojke. Uvijek navedi lokaciju. Bez emotikona u naslovu.'),
                        TagsInput::make('voice.hashtags')->label('Fiksni hashtagovi')->placeholder('studentskiposao'),
                        TextInput::make('voice.cta')->label('Poziv na akciju')->placeholder('Prijavi se na studentski-poslovi.hr'),
                        TextInput::make('voice.pitch')->label('Rečenica o brendu')
                            ->placeholder('Svi letci na jednom mjestu: dodirni proizvod i on je na listi, s cijenom.')
                            ->helperText('Što brend radi, u jednoj rečenici. Ide na završni slajd i u TikTok i Instagram tekst, gdje poveznica nije klikabilna i gledatelj inače vidi samo ponudu, a ne razlog da dođe. Bez iznosa: tekst smije navesti samo iznose iz podataka stavke.')
                            ->maxLength(140)->columnSpanFull(),
                        TextInput::make('voice.cta_note')->label('Napomena uz poziv na akciju')
                            ->placeholder('Besplatno na App Storeu i Google Playu · 20 dana bez kartice')
                            ->helperText('Kratak redak ispod adrese na završnom slajdu.')
                            ->maxLength(90)->columnSpanFull(),
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

                Section::make('Pregledi')
                    ->description('Serije preglednih objava s najboljim aktivnim stavkama (npr. „Top 7 akcija u Kauflandu“ svake srijede). Idu na kanale kojima je uključena automatska objava (bez ručnih FB grupa), s njihovim postavkama. Stavka smije u pregled i kad je već imala svoju objavu, ali ne dvaput u 7 dana.')
                    ->schema([
                        Repeater::make('digests')->label('')
                            ->schema([
                                // Which series built a draft, so each is built once a day; not shown.
                                Hidden::make('key')->default(fn (): string => (string) Str::uuid()),
                                TextInput::make('name')->label('Naziv (interno)')->required()->maxLength(80)
                                    ->placeholder('Kaufland srijedom'),
                                Toggle::make('enabled')->label('Uključeno')->default(true)->inline(false),
                                TextInput::make('tag')->label('Samo stavke s oznakom')->maxLength(64)->placeholder('kaufland')
                                    ->helperText('Oznaka iz feeda (lanac, grad…). Prazno = sve stavke.'),
                                TextInput::make('headline')->label('Naslov')->maxLength(120)
                                    ->placeholder('Top {count} akcija u Kauflandu')
                                    ->helperText('{count} = broj stavki. Prazno = „Top N … ovog tjedna“.')
                                    ->columnSpanFull(),
                                CheckboxList::make('days')->label('Dani')
                                    ->options([1 => 'pon', 2 => 'uto', 3 => 'sri', 4 => 'čet', 5 => 'pet', 6 => 'sub', 7 => 'ned'])
                                    ->default([1, 4])->columns(7)->columnSpanFull(),
                                TimePicker::make('time')->label('Vrijeme objave')->seconds(false)->default(DigestSeries::DEFAULT_TIME),
                                TextInput::make('count')->label('Broj stavki')->numeric()->minValue(2)->maxValue(DigestBuilder::MAX_ITEMS)
                                    ->default(DigestSeries::DEFAULT_COUNT),
                                Select::make('kind')->label('Vrsta stavki')->options(ContentKind::class)->default(ContentKind::Job->value),
                                Select::make('formats.meta')->label('Facebook i Instagram')
                                    ->options(['carousel' => 'Carousel', 'video' => 'Reel'])
                                    ->default('carousel')->selectablePlaceholder(false),
                                Select::make('formats.tiktok')->label('TikTok')
                                    ->options(['carousel' => 'Foto carousel', 'video' => 'Video'])
                                    ->default('carousel')->selectablePlaceholder(false),
                                TextInput::make('seconds_per_slide')->label('Sekundi po slajdu')->numeric()
                                    ->default(VideoRenderer::DEFAULT_SECONDS_PER_SLIDE)->minValue(1.5)->maxValue(5)->step(0.25)
                                    ->helperText('Za Reel/video. Kraće zadržava brži ritam.'),
                            ])
                            ->columns(3)
                            ->default([])
                            ->addActionLabel('Dodaj seriju')
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->collapsible(),
                    ])
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
