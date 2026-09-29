<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Schemas;

use App\Drafting\DigestBuilder;
use App\Drafting\DigestSeries;
use App\Enums\ContentKind;
use App\Filament\Support\VoiceoverPanel;
use App\Models\Brand;
use App\Rendering\VideoRenderer;
use App\Voiceover\VoiceoverException;
use App\Voiceover\VoiceoverSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Component;

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
                        TextInput::make('voice.activation')->label('Prvi korak nakon preuzimanja')->maxLength(100)
                            ->helperText('Jedna konkretna radnja, npr. „Dodaj prvi proizvod s letka“. Zamjenjuje poziv na spremanje i dijeljenje objave.'),
                        Select::make('voice.video_style')->label('Ritam videa')
                            ->options(['standard' => 'Blagi prijelazi', 'direct' => 'Izravni rezovi, kraći uvod'])
                            ->helperText('Izravni rezovi: uvod do 2,5 s, ponude ostaju čitljive, završni poziv najmanje 3,5 s.'),
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

                Section::make('Voice-over (ElevenLabs)')
                    ->description('Videi (Reels i TikTok) dobivaju glas koji izgovara ono što je na slajdovima: proizvod, cijenu, popust i poziv brenda. Tekst se piše iz podataka stavke, iznosi se provjeravaju kao i u tekstu objave, a glas se plaća samo jednom po rečenici. Kanal ili pravilo automatske objave mogu voice-over uključiti ili isključiti za sebe.')
                    ->schema([
                        Placeholder::make('voiceover_status')->label('Veza')
                            ->content(fn (): string => VoiceoverPanel::status())
                            ->columnSpanFull(),
                        Toggle::make('voiceover.enabled')->label('Videi ovog brenda dobivaju voice-over')
                            ->default(false)
                            ->helperText('Vrijedi za sve nove videe, i one koje hub sam radi (pravila i serije pregleda). Bez odabranog glasa ili ključa videi ostaju kakvi jesu.')
                            ->columnSpanFull(),
                        Select::make('voiceover.voice_id')->label('Glas')
                            ->options(fn (): array => VoiceoverPanel::voices())
                            ->getOptionLabelUsing(fn (mixed $value): ?string => VoiceoverPanel::voices()[$value] ?? (filled($value) ? (string) $value : null))
                            ->searchable()
                            ->helperText('★ = ElevenLabs navodi glas kao provjeren za hrvatski. Ako nijedan nije dovoljno dobar, u ElevenLabsu izradi vlastiti glas i on će se ovdje pojaviti.')
                            ->visible(fn (): bool => VoiceoverPanel::voices() !== []),
                        TextInput::make('voiceover.voice_id')->label('ID glasa')
                            ->maxLength(64)
                            ->helperText(fn (): string => 'Popis glasova se ne može učitati'.(VoiceoverPanel::voicesError() !== null ? ' ('.VoiceoverPanel::voicesError().')' : '').'. Zalijepi ID iz ElevenLabs → Voices → ID.')
                            ->visible(fn (): bool => VoiceoverPanel::voices() === []),
                        Select::make('voiceover.model')->label('Model')
                            ->options((array) config('elevenlabs.models', []))
                            ->placeholder(fn (): string => 'Zadano ('.(config('elevenlabs.models')[config('elevenlabs.model')] ?? config('elevenlabs.model')).')')
                            ->helperText('Novi model isprobaj preslušavanjem prije nego ga uključiš.'),
                        TextInput::make('voiceover.speed')->label('Brzina')->numeric()
                            ->minValue(VoiceoverSettings::MIN_SPEED)->maxValue(VoiceoverSettings::MAX_SPEED)->step(0.05)
                            ->placeholder('1,05')
                            ->helperText('0,7–1,2, zadano 1,05. Brže zvuči življe i skraćuje video; cijene ostaju razumljive do oko 1,1.'),
                        Select::make('voiceover.music')->label('Glasnoća glazbe ispod glasa')
                            ->options(['quiet' => 'Tiho', 'medium' => 'Srednje (zadano)', 'loud' => 'Glasnije'])
                            ->helperText('Glazba brenda se uz to još stišava dok glas govori.'),
                        TextInput::make('voiceover.outro')->label('Završna rečenica')
                            ->maxLength(200)
                            ->placeholder('Preuzmi Listo besplatno i dodaj prvi proizvod s letka.')
                            ->helperText('Izgovara se na završnom slajdu. Prazno = poziv na akciju i prvi korak iz „Glas brenda“. Piši kako se govori i bez adrese stranice.')
                            ->columnSpanFull(),
                        Repeater::make('voiceover.pronunciations')->label('Izgovor imena')
                            ->schema([
                                TextInput::make('find')->label('Piše se')->required()->maxLength(60)->placeholder('SPAR'),
                                TextInput::make('say')->label('Čita se')->required()->maxLength(80)->placeholder('Spar'),
                            ])
                            ->columns(2)
                            ->default([])
                            ->addActionLabel('Dodaj ime')
                            ->itemLabel(fn (array $state): ?string => filled($state['find'] ?? null) ? $state['find'].' → '.($state['say'] ?? '…') : null)
                            ->collapsible()
                            ->helperText('Za imena koja glas čita krivo (trgovački lanci, kratice). Zamjena vrijedi za cijeli tekst prije nego ga glas čuje. Brojeve, iznose i datume hub sam izgovara.')
                            ->columnSpanFull(),
                        Actions::make([
                            Action::make('previewVoiceover')
                                ->label('Preslušaj glas')
                                ->icon(Heroicon::OutlinedSpeakerWave)
                                ->color('gray')
                                ->modalHeading('Preslušaj glas')
                                ->modalDescription('Koristi trenutne postavke iz obrasca, i one koje još nisu spremljene. Košta koliko ima znakova; ista rečenica se ne naplaćuje dvaput.')
                                ->modalSubmitActionLabel('Izgovori')
                                ->schema([
                                    Textarea::make('text')->label('Tekst')->rows(3)->required()->maxLength(300)->default(VoiceoverPanel::SAMPLE),
                                ])
                                ->action(function (array $data, Component $livewire, ?Brand $record = null): void {
                                    try {
                                        $sample = VoiceoverPanel::sample((array) data_get($livewire, 'data.voiceover', []), (string) $data['text'], $record);
                                    } catch (VoiceoverException $e) {
                                        Notification::make()->title('Glas nije izgovoren')->body($e->getMessage())->danger()->send();

                                        return;
                                    }

                                    Notification::make()
                                        ->title('Probni zapis je spreman')
                                        ->body('Glas čita: '.$sample['spoken'])
                                        ->actions([
                                            Action::make('play')->label('Preslušaj')->button()->url(route('voiceovers.audio', $sample['clip']), shouldOpenInNewTab: true),
                                        ])
                                        ->success()
                                        ->persistent()
                                        ->send();
                                }),
                        ])->key('voiceoverActions')->columnSpanFull(),
                    ])
                    ->columns(2)
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
                                    ->default('carousel')->selectablePlaceholder(false)
                                    ->helperText('TikTok uvijek dobiva video.'),
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
                        TextInput::make('daily_post_limit')->label('Najviše automatskih objava dnevno')->numeric()->minValue(1)->maxValue(50)
                            ->helperText('Ukupno za brend, po lokalnom danu objave: pojedinačne stavke i pregledi zajedno, sa svih izvora. Kad je dan pun, automatika čeka idući. Ručne objave se broje, ali ih limit ne zaustavlja. Prazno = bez limita.'),
                        Repeater::make('posting_windows')->label('')
                            ->schema([
                                TimePicker::make('from')->label('Od')->seconds(false)->required(),
                                TimePicker::make('to')->label('Do')->seconds(false)->required(),
                            ])->columns(2)->default([['from' => '08:00', 'to' => '20:00']])->reorderable(false),
                    ]),
            ]);
    }
}
