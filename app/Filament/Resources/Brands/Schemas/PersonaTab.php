<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Schemas;

use App\Ai\OpenAiException;
use App\Filament\Support\VoiceoverPanel;
use App\Models\Brand;
use App\Persona\CharacterImages;
use App\Persona\Persona;
use App\Persona\PersonaDrafter;
use App\Persona\VoiceDesigner;
use App\Video\FalException;
use App\Voiceover\VoiceoverException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Brendovi → AI lik: everything a character needs, made here — the profile from an idea (OpenAI), the picture fal moves
 * the lips of (fal), and the voice (chosen from the account and heard, or designed from a description on ElevenLabs).
 * What the tools make fills the form; nothing is kept until the brand is saved, except a designed voice a person keeps,
 * which ElevenLabs stores in the account.
 */
final class PersonaTab
{
    public const SAMPLE = [
        'en' => 'Hey, it is me. Before you buy anything today, wait twenty-four hours. Most of the time, you will not miss it.',
        'hr' => 'Bok, ja sam. Prije nego što danas išta kupiš, pričekaj dvadeset i četiri sata. Većinom ti neće nedostajati.',
    ];

    public static function make(): Tab
    {
        $enabled = fn (Get $get): bool => (bool) $get('persona.enabled');

        return Tab::make('AI lik')->icon(Heroicon::OutlinedUserCircle)->schema([
            Section::make('AI lik')
                ->description('Brend može biti AI lik koji vodi svoje kanale i priča o jednom području. Svako jutro AI predloži nekoliko tema za taj dan; ti odabereš jednu, AI napiše skriptu, a ti je pročitaš i odobriš. Ništa se ne objavljuje samo. Lika, sliku i glas možeš napraviti ovdje, od ideje.')
                ->schema([
                    Toggle::make('persona.enabled')->label('Ovaj brend je AI lik')->live()->columnSpanFull(),
                    Actions::make([self::draftAction()])->key('personaIdeaActions')->visible($enabled)->columnSpanFull(),
                    TextInput::make('persona.name')->label('Ime lika')->maxLength(80)->placeholder('Mia')
                        ->helperText('Prazno = naziv brenda.'),
                    Select::make('persona.language')->label('Jezik tema i skripti')
                        ->options(Persona::LANGUAGES)->default(Persona::DEFAULT_LANGUAGE)->selectablePlaceholder(false),
                    Textarea::make('persona.description')->label('Tko je lik')->rows(3)->columnSpanFull()
                        ->placeholder('Smirena savjetnica za novac u tridesetima koja objašnjava osobne financije bez žargona.')
                        ->required($enabled),
                    Textarea::make('persona.goal')->label('Cilj kanala')->rows(2)->columnSpanFull()
                        ->placeholder('Pomoći mladima da manje troše na impuls i skupe prvu rezervu.')
                        ->required($enabled),
                    TextInput::make('persona.audience')->label('Publika')->maxLength(200)->placeholder('18–30, prvi posao'),
                    TextInput::make('persona.tone')->label('Ton')->maxLength(200)->placeholder('topao, izravan, malo duhovit'),
                    Textarea::make('persona.avoid')->label('O čemu lik nikad ne priča')->rows(2)->columnSpanFull()
                        ->placeholder('Kripto, preporuke dionica, politika.'),
                ])->columns(2),

            Section::make('Izgled i ambijent')
                ->description('Slika lika je lik: fal po njoj pomiče usne u svakom kadru u kojem lik govori, pa neka je uspravna, lice jasno, a iza lika prostor iz ambijenta. Napravi nekoliko prijedloga, odaberi jedan i po potrebi ga doradi. Svaka slika na falu košta oko 0,07 $.')
                ->visible($enabled)
                ->schema([
                    Textarea::make('persona.appearance')->label('Izgled lika')->rows(3)
                        ->placeholder('Žena u tridesetima, kosa do ramena, krem pleteni džemper.'),
                    Textarea::make('persona.ambience')->label('Ambijent')->rows(3)
                        ->placeholder('Svijetla mala kuhinja s biljkama na prozoru, jutarnje svjetlo.'),
                    Actions::make([self::proposeImagesAction(), self::refineImageAction()])->key('personaImageActions')->columnSpanFull(),
                    Hidden::make('persona.image_candidates')->default([])->dehydrated(false),
                    Placeholder::make('persona_image_candidates')->hiddenLabel()->columnSpanFull()
                        ->visible(fn (Get $get): bool => (array) $get('persona.image_candidates') !== [])
                        ->content(fn (Get $get): HtmlString => self::imageGrid((array) $get('persona.image_candidates'))),
                    Radio::make('persona.image_pick')->label('Koja slika je lik?')->inline()->live()->dehydrated(false)->columnSpanFull()
                        ->visible(fn (Get $get): bool => (array) $get('persona.image_candidates') !== [])
                        ->options(fn (Get $get): array => self::numbered((array) $get('persona.image_candidates'), 'Slika'))
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if (filled($state)) {
                                $set('persona.appearance_image', [(string) Str::uuid() => $state]);
                            }
                        }),
                    FileUpload::make('persona.appearance_image')->label('Slika lika')->image()->disk('public')
                        ->directory('brands/persona')->visibility('public')->maxSize(8192)
                        ->helperText('Odabrana slika, ili svoja (uspravna, lice jasno).'),
                    FileUpload::make('persona.ambience_image')->label('Slika ambijenta')->image()->disk('public')
                        ->directory('brands/persona')->visibility('public')->maxSize(8192)
                        ->helperText('Neobavezno; opis ambijenta je ono što ide u upute.'),
                ])->columns(2),

            Section::make('Glas lika')
                ->description('Glas iz tvog ElevenLabs računa (isti izbor kao u kartici Voice-over) ili nov, napravljen iz opisa: ElevenLabs ponudi tri, presluša ih i zadržiš jedan. Brojeve glasu uvijek ispisuje hub.')
                ->visible($enabled)
                ->schema([
                    Placeholder::make('persona_voice_current')->label('Sada')
                        ->content(fn (Get $get): string => filled($get('voiceover.voice_id'))
                            ? (VoiceoverPanel::voices()[$get('voiceover.voice_id')] ?? (string) $get('voiceover.voice_id'))
                            : 'Glas nije odabran (kartica Voice-over ili nov glas ispod).'),
                    Textarea::make('persona.voice_description')->label('Opis glasa')->rows(2)
                        ->placeholder('Topao ženski glas u tridesetima, američki naglasak, smiren tempo, prijateljski.')
                        ->helperText('Za novi glas: spol, dob, naglasak, tempo, toplina. Najbolje na engleskom.'),
                    Actions::make([self::previewVoiceAction(), self::designVoiceAction()])->key('personaVoiceActions')->columnSpanFull(),
                    Hidden::make('persona.voice_candidates')->default([])->dehydrated(false),
                    Placeholder::make('persona_voice_candidates')->hiddenLabel()->columnSpanFull()
                        ->visible(fn (Get $get): bool => (array) $get('persona.voice_candidates') !== [])
                        ->content(fn (Get $get): HtmlString => self::voiceList((array) $get('persona.voice_candidates'))),
                    Radio::make('persona.voice_pick')->label('Koji glas zadržati?')->inline()->live()->dehydrated(false)->columnSpanFull()
                        ->visible(fn (Get $get): bool => (array) $get('persona.voice_candidates') !== [])
                        ->options(fn (Get $get): array => self::numbered(array_column((array) $get('persona.voice_candidates'), 'id'), 'Glas')),
                    Actions::make([self::keepVoiceAction()])->key('personaVoiceKeep')->columnSpanFull()
                        ->visible(fn (Get $get): bool => filled($get('persona.voice_pick'))),
                ])->columns(2),

            Section::make('Teme')
                ->visible($enabled)
                ->schema([
                    TextInput::make('persona.topics_per_day')->label('Tema dnevno')->numeric()
                        ->minValue(1)->maxValue(Persona::MAX_TOPICS_PER_DAY)->default(Persona::DEFAULT_TOPICS_PER_DAY),
                    TimePicker::make('persona.suggest_at')->label('Prijedlozi stižu u')->seconds(false)
                        ->default(Persona::DEFAULT_SUGGEST_AT)
                        ->helperText('Lokalno vrijeme brenda. Teme se predlažu jednom dnevno; više ih možeš tražiti na ekranu Teme.'),
                ])->columns(2),
        ]);
    }

    private static function draftAction(): Action
    {
        return Action::make('draftPersona')
            ->label('Opiši lika iz ideje')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->modalHeading('Lik iz ideje')
            ->modalDescription('AI popuni polja lika (i opis izgleda, ambijenta i glasa). Ništa se ne sprema dok ne spremiš brend; polja koja već imaš bit će zamijenjena.')
            ->modalSubmitActionLabel('Opiši lika')
            ->schema([
                Textarea::make('idea')->label('Ideja')->rows(3)->required()->maxLength(1000)
                    ->placeholder('Savjetnica za novac za mlade s prvim poslom, objašnjava bez žargona, iz svoje kuhinje.'),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                try {
                    $fields = app(PersonaDrafter::class)->draft((string) $data['idea'], (string) ($get('persona.language') ?: Persona::DEFAULT_LANGUAGE));
                } catch (OpenAiException $e) {
                    Notification::make()->title('Lik nije opisan')->body($e->getMessage())->danger()->send();

                    return;
                }

                foreach ($fields as $field => $value) {
                    $set("persona.{$field}", $value);
                }

                Notification::make()->title('Lik je opisan')->body('Pročitaj i ispravi polja, pa spremi brend.')->success()->send();
            });
    }

    private static function proposeImagesAction(): Action
    {
        return Action::make('proposeImages')
            ->label('Napravi slike lika')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->modalHeading('Slike lika')
            ->modalDescription(fn (): string => sprintf('fal napravi %d prijedloga iz opisa izgleda i ambijenta (oko %s $). Traje do pola minute.', (int) config('fal.image_candidates', 4), number_format((int) config('fal.image_candidates', 4) * 0.0675, 2, ',', '')))
            ->modalSubmitActionLabel('Napravi')
            ->schema([
                Textarea::make('extra')->label('Još nešto (neobavezno)')->rows(2)->maxLength(500)
                    ->placeholder('Smiješi se, gleda ravno u kameru.'),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                $appearance = mb_trim((string) $get('persona.appearance'));

                if ($appearance === '') {
                    Notification::make()->title('Opiši izgled lika')->body('Slika se radi iz polja „Izgled lika“.')->warning()->send();

                    return;
                }

                set_time_limit(180);

                try {
                    $paths = app(CharacterImages::class)->propose($appearance, mb_trim((string) $get('persona.ambience')), mb_trim((string) ($data['extra'] ?? '')));
                } catch (FalException $e) {
                    Notification::make()->title('Slike nisu napravljene')->body($e->getMessage())->danger()->send();

                    return;
                }

                $set('persona.image_candidates', $paths);
                $set('persona.image_pick', null);
                Notification::make()->title('Slike su spremne')->body('Odaberi jednu ispod; spremi brend da ostane.')->success()->send();
            });
    }

    private static function refineImageAction(): Action
    {
        return Action::make('refineImage')
            ->label('Doradi sliku lika')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (Get $get): bool => self::imagePath($get('persona.appearance_image')) !== null)
            ->modalHeading('Doradi sliku lika')
            ->modalDescription('Ista osoba i prostor, promijeni se samo ono što napišeš. fal napravi 2 prijedloga (oko 0,14 $).')
            ->modalSubmitActionLabel('Doradi')
            ->schema([
                Textarea::make('instruction')->label('Što promijeniti')->rows(2)->required()->maxLength(500)
                    ->placeholder('Kraća kosa, zeleni džemper, toplije svjetlo.'),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                $path = self::imagePath($get('persona.appearance_image'));

                if ($path === null || ! Storage::disk(CharacterImages::DISK)->exists($path)) {
                    Notification::make()->title('Nema slike za doradu')->body('Odaberi ili spremi sliku lika prvo.')->warning()->send();

                    return;
                }

                set_time_limit(180);

                try {
                    $paths = app(CharacterImages::class)->refine($path, (string) $data['instruction']);
                } catch (FalException $e) {
                    Notification::make()->title('Slika nije dorađena')->body($e->getMessage())->danger()->send();

                    return;
                }

                $set('persona.image_candidates', [$path, ...$paths]);
                $set('persona.image_pick', null);
                Notification::make()->title('Dorade su spremne')->body('Prva je dosadašnja slika; odaberi koju zadržavaš.')->success()->send();
            });
    }

    private static function previewVoiceAction(): Action
    {
        return Action::make('previewPersonaVoice')
            ->label('Preslušaj glas lika')
            ->icon(Heroicon::OutlinedSpeakerWave)
            ->color('gray')
            ->modalHeading('Preslušaj glas lika')
            ->modalDescription('Odabrani glas, na jeziku lika, s postavkama iz obrasca (i onima koje još nisu spremljene).')
            ->modalSubmitActionLabel('Izgovori')
            ->fillForm(fn (Get $get): array => ['text' => self::SAMPLE[(string) ($get('persona.language') ?: Persona::DEFAULT_LANGUAGE)] ?? self::SAMPLE['en']])
            ->schema([
                Textarea::make('text')->label('Tekst')->rows(3)->required()->maxLength(300),
            ])
            ->action(function (array $data, Get $get, Component $livewire, ?Brand $record = null): void {
                try {
                    $sample = VoiceoverPanel::sample((array) data_get($livewire, 'data.voiceover', []), (string) $data['text'], $record, language: (string) ($get('persona.language') ?: Persona::DEFAULT_LANGUAGE));
                } catch (VoiceoverException $e) {
                    Notification::make()->title('Glas nije izgovoren')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Probni zapis je spreman')
                    ->body('Glas čita: '.$sample['spoken'])
                    ->actions([Action::make('play')->label('Preslušaj')->button()->url(route('voiceovers.audio', $sample['clip']), shouldOpenInNewTab: true)])
                    ->success()->persistent()->send();
            });
    }

    private static function designVoiceAction(): Action
    {
        return Action::make('designVoice')
            ->label('Napravi glas iz opisa')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Novi glas iz opisa')
            ->modalDescription('ElevenLabs napravi tri glasa iz „Opis glasa“ i za svaki kratak uzorak (troši ElevenLabs kredite). Nijedan se ne sprema dok ne zadržiš jedan.')
            ->action(function (Get $get, Set $set): void {
                set_time_limit(180);

                try {
                    $previews = app(VoiceDesigner::class)->propose((string) $get('persona.voice_description'));
                } catch (VoiceoverException $e) {
                    Notification::make()->title('Glasovi nisu napravljeni')->body($e->getMessage())->danger()->send();

                    return;
                }

                $set('persona.voice_candidates', $previews);
                $set('persona.voice_pick', null);
                Notification::make()->title('Tri glasa su spremna')->body('Preslušaj ih ispod i zadrži jedan.')->success()->send();
            });
    }

    private static function keepVoiceAction(): Action
    {
        return Action::make('keepVoice')
            ->label('Zadrži odabrani glas')
            ->icon(Heroicon::OutlinedCheck)
            ->action(function (Get $get, Set $set): void {
                $name = mb_trim((string) ($get('persona.name') ?: $get('name') ?: 'AI lik'));

                try {
                    $voiceId = app(VoiceDesigner::class)->keep((string) $get('persona.voice_pick'), "{$name} (AI lik)", mb_trim((string) $get('persona.voice_description')));
                } catch (VoiceoverException $e) {
                    Notification::make()->title('Glas nije zadržan')->body($e->getMessage())->danger()->send();

                    return;
                }

                $set('voiceover.voice_id', $voiceId);
                $set('voiceover.enabled', true);
                $set('persona.voice_candidates', []);
                $set('persona.voice_pick', null);
                Notification::make()->title('Glas je zadržan')->body('Spremljen je u ElevenLabs i odabran za ovaj brend. Spremi brend.')->success()->send();
            });
    }

    /**
     * A FileUpload's state is the path, or a list of them keyed by an upload id.
     */
    private static function imagePath(mixed $state): ?string
    {
        $path = is_array($state) ? (array_values($state)[0] ?? null) : $state;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function numbered(array $values, string $label): array
    {
        $options = [];

        foreach (array_values($values) as $i => $value) {
            $options[(string) $value] = $label.' '.($i + 1);
        }

        return $options;
    }

    /**
     * @param  list<string>  $paths
     */
    private static function imageGrid(array $paths): HtmlString
    {
        $items = '';

        foreach (array_values($paths) as $i => $path) {
            $items .= sprintf(
                '<figure class="hub-candidate"><img class="hub-candidate__img" src="%s" alt="%s" loading="lazy"><figcaption class="hub-candidate__label">%s</figcaption></figure>',
                e(Storage::disk(CharacterImages::DISK)->url($path)),
                e('Prijedlog slike lika '.($i + 1)),
                e('Slika '.($i + 1)),
            );
        }

        return new HtmlString('<div class="hub-candidates">'.$items.'</div>');
    }

    /**
     * @param  list<array{id: string, path: string}>  $previews
     */
    private static function voiceList(array $previews): HtmlString
    {
        $items = '';

        foreach (array_values($previews) as $i => $preview) {
            $items .= sprintf(
                '<figure class="hub-candidate hub-candidate--voice"><figcaption class="hub-candidate__label">%s</figcaption><audio class="hub-candidate__audio" controls preload="none" src="%s"></audio></figure>',
                e('Glas '.($i + 1)),
                e(Storage::disk(VoiceDesigner::DISK)->url((string) ($preview['path'] ?? ''))),
            );
        }

        return new HtmlString('<div class="hub-candidates hub-candidates--voices">'.$items.'</div>');
    }
}
