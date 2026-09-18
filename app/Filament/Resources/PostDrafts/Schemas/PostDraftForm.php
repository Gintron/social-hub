<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostDrafts\Schemas;

use App\Actions\ChangeVariantFormat;
use App\Actions\PrepareVariantMedia;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Publishing\FormatCheck;
use App\Rendering\TemplateRegistry;
use App\Rendering\VideoRenderer;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The review screen: one tab per channel — its format, media and text side by side — and the
 * draft's internal details folded away underneath.
 *
 * Form state lives under `channels.v{id}`; EditPostDraft fills it and saves it through
 * App\Actions\UpdateVariant. The format is the exception: it takes effect the moment it changes
 * (ChangeVariantFormat), because it starts a render for that channel.
 */
final class PostDraftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Sadržaj je promijenjen na izvoru')
                    ->warning()
                    ->visible(fn (?PostDraft $record): bool => $record !== null && $record->staleItems()->isNotEmpty())
                    ->description(fn (?PostDraft $record): string => 'Izmijenjeno otkad je nacrt napravljen: '
                        .($record?->staleItems()->pluck('title')->implode(', ') ?? '')
                        .'. Provjeri tekst i renderiraj medije ponovno prije objave.')
                    ->columnSpanFull(),

                Tabs::make('Kanali')
                    ->tabs(fn (?PostDraft $record): array => $record === null ? [] : self::channels($record))
                    ->persistTabInQueryString('kanal')
                    ->columnSpanFull(),

                Section::make('Detalji')
                    ->description('Interni naslov i bilješke; ne objavljuju se.')
                    ->schema([
                        TextInput::make('title')->label('Interni naslov')->maxLength(300),
                        Textarea::make('notes')->label('Bilješke')->rows(2),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return list<Tab>
     */
    private static function channels(PostDraft $draft): array
    {
        $variants = $draft->variants()->with(['account', 'media', 'draft.brand', 'draft.contentItems'])->orderBy('id')->get();
        $platformCounts = $variants->countBy(fn (PostVariant $variant): string => $variant->platform->value);

        return $variants
            ->map(fn (PostVariant $variant): Tab => self::channel($variant, $platformCounts[$variant->platform->value] > 1))
            ->values()
            ->all();
    }

    private static function channel(PostVariant $variant, bool $nameAccount): Tab
    {
        $id = $variant->id;
        $path = "channels.v{$id}";
        $platform = $variant->platform;
        $locked = $variant->isLocked();
        [$badge, $color] = self::state($variant);

        return Tab::make(self::label($variant, $nameAccount))
            ->icon($variant->format()->icon())
            ->badge($badge)
            ->badgeColor($color)
            ->schema([
                Grid::make(['default' => 1, 'lg' => 2])->schema([
                    Group::make([
                        Toggle::make("{$path}.enabled")
                            ->label('Objavi na ovaj kanal')
                            ->disabled($locked),

                        ToggleButtons::make("{$path}.format")
                            ->label('Format')
                            ->options(self::formatOptions($platform))
                            ->icons(collect($platform->formats())->mapWithKeys(fn (ContentFormat $format): array => [$format->value => $format->icon()])->all())
                            ->grouped()
                            ->live()
                            ->disabled($locked)
                            ->helperText(self::formatHelp($platform))
                            ->afterStateUpdated(fn (?string $state, ?string $old, Set $set) => self::changeFormat($id, $path, $state, $old, $set)),

                        Textarea::make("{$path}.caption")
                            ->label('Tekst objave')
                            ->rows(12)
                            ->required()
                            ->live(onBlur: true)
                            ->disabled($locked)
                            ->helperText(fn (?string $state, Get $get): string => self::captionHelp((string) $state, $platform, ContentFormat::tryFrom((string) $get("{$path}.format")))),

                        Select::make("{$path}.delivery")
                            ->label('Način objave na TikToku')
                            ->options([
                                'direct' => 'Objavi izravno',
                                'inbox' => 'Pošalji u TikTok inbox (dodaš trending zvuk i objaviš u aplikaciji)',
                            ])
                            ->selectablePlaceholder(false)
                            ->live()
                            ->disabled($locked)
                            ->helperText('Inbox: medij stiže kao nacrt u TikTok aplikaciju; tekst zalijepiš ondje, a ovdje označiš kao ručno objavljeno.')
                            ->visible($platform === Platform::TikTok),

                        Select::make("{$path}.privacy_level")
                            ->label('Vidljivost na TikToku')
                            ->options([
                                'SELF_ONLY' => 'Samo ja (obavezno dok app nije auditirana)',
                                'PUBLIC_TO_EVERYONE' => 'Javno',
                                'MUTUAL_FOLLOW_FRIENDS' => 'Prijatelji',
                                'FOLLOWER_OF_CREATOR' => 'Pratitelji',
                            ])
                            ->selectablePlaceholder(false)
                            ->disabled($locked)
                            ->helperText('TikTok dopušta samo razine koje sam vrati za taj račun; hub pada natrag na dopuštenu.')
                            ->visible(fn (Get $get): bool => $platform === Platform::TikTok && $get("{$path}.delivery") !== 'inbox'),

                        Toggle::make("{$path}.auto_add_music")
                            ->label('TikTok doda glazbu ispod fotografija')
                            ->helperText('Pjesmu bira TikTok; API je ne može odabrati.')
                            ->disabled($locked)
                            ->visible(fn (Get $get): bool => $platform === Platform::TikTok
                                && in_array($get("{$path}.format"), [ContentFormat::Image->value, ContentFormat::Carousel->value], true)
                                && $get("{$path}.delivery") !== 'inbox'),
                    ]),

                    Group::make([
                        View::make('filament.forms.channel-media')->viewData(['variantId' => $id]),
                        Actions::make(self::mediaActions($variant))->visible(! $locked),
                    ]),
                ]),
            ]);
    }

    /**
     * @return array{0: string, 1: string} badge text and colour
     */
    private static function state(PostVariant $variant): array
    {
        $format = self::formatLabel($variant->platform, $variant->format());

        return match (true) {
            $variant->status === VariantStatus::Disabled => ['isključeno', 'gray'],
            $variant->status === VariantStatus::Skipped => ['preskočeno', 'gray'],
            in_array($variant->status, [VariantStatus::Published, VariantStatus::ManualDone], true) => ['objavljeno', 'success'],
            $variant->status === VariantStatus::ManualPending => ['čeka ručnu objavu', 'warning'],
            in_array($variant->status, [VariantStatus::Queued, VariantStatus::Publishing], true) => ['objavljuje se', 'info'],
            $variant->status === VariantStatus::Failed => ['greška', 'danger'],
            $variant->isRendering() => ["{$format} · renderira se", 'warning'],
            app(FormatCheck::class)->problem($variant) !== null => ["{$format} · nije spremno", 'danger'],
            default => ["{$format} · spremno", 'success'],
        };
    }

    private static function label(PostVariant $variant, bool $nameAccount): string
    {
        $platform = match ($variant->platform) {
            Platform::FacebookPage => 'Facebook',
            Platform::InstagramBusiness => 'Instagram',
            Platform::FacebookGroup => 'FB grupa',
            Platform::TikTok => 'TikTok',
        };

        return $nameAccount && filled($variant->account?->name) ? "{$platform} · {$variant->account->name}" : $platform;
    }

    /**
     * @return array<string, string>
     */
    private static function formatOptions(Platform $platform): array
    {
        return collect($platform->formats())
            ->mapWithKeys(fn (ContentFormat $format): array => [$format->value => self::formatLabel($platform, $format)])
            ->all();
    }

    /**
     * A video on Meta goes out as a Reel; people know it by that name.
     */
    private static function formatLabel(Platform $platform, ContentFormat $format): string
    {
        return $format === ContentFormat::Video && in_array($platform, [Platform::FacebookPage, Platform::InstagramBusiness], true)
            ? 'Reel'
            : $format->label();
    }

    private static function formatHelp(Platform $platform): string
    {
        return match ($platform) {
            Platform::FacebookPage => 'Link: samo tekst s pregledom stranice. Reel: uspravni video.',
            Platform::InstagramBusiness => 'Carousel: 2–10 slika istog omjera. Reel: uspravni video.',
            Platform::TikTok => 'Slika i carousel idu kao TikTok foto objava (do 35 slika).',
            Platform::FacebookGroup => 'Tekst i medij zalijepiš u grupu ručno.',
        };
    }

    private static function changeFormat(int $id, string $path, ?string $state, ?string $old, Set $set): void
    {
        $variant = PostVariant::query()->find($id);
        $format = ContentFormat::tryFrom((string) $state);

        if ($variant === null || $format === null || $state === $old) {
            return;
        }

        try {
            app(ChangeVariantFormat::class)->execute($variant, $format);
        } catch (Throwable $e) {
            $set("{$path}.format", $old);
            Notification::make()->title('Format nije promijenjen')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Format: '.self::formatLabel($variant->platform, $format))
            ->body($format->needsMedia()
                ? 'Medij za ovaj kanal priprema se u pozadini; ostali kanali zadržavaju svoje.'
                : 'Objava ide kao tekst s pregledom linka.')
            ->success()
            ->send();
    }

    /**
     * @return list<Action>
     */
    private static function mediaActions(PostVariant $variant): array
    {
        $id = $variant->id;
        $format = $variant->format();

        if (! $format->needsMedia()) {
            return [];
        }

        $kind = $variant->draft?->contentItems->first()?->kind;
        $templates = app(TemplateRegistry::class);

        $actions = [
            Action::make("render_{$id}")
                ->label('Renderiraj ponovno')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->modalHeading(self::formatLabel($variant->platform, $format).': renderiraj ponovno')
                ->modalSubmitActionLabel('Renderiraj')
                ->schema($format === ContentFormat::Video ? self::videoFields($variant) : [
                    Select::make('template')
                        ->label('Predložak')
                        ->options($templates->optionsFor($kind))
                        ->placeholder('Automatski za ovaj kanal'),
                ])
                ->action(function (array $data) use ($id): void {
                    $variant = PostVariant::query()->find($id);

                    if ($variant === null) {
                        return;
                    }

                    try {
                        app(PrepareVariantMedia::class)->execute(
                            [$variant],
                            fresh: true,
                            templateKey: filled($data['template'] ?? null) ? (string) $data['template'] : null,
                            video: $data,
                        );
                    } catch (Throwable $e) {
                        Notification::make()->title('Render nije pokrenut')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Renderiram u pozadini')->body('Pregled se osvježi sam kad medij bude gotov.')->success()->send();
                }),
        ];

        if ($format === ContentFormat::Carousel && $kind !== null) {
            $actions[] = Action::make("add_slide_{$id}")
                ->label('Dodaj slajd')
                ->icon(Heroicon::OutlinedPlus)
                ->color('gray')
                ->modalSubmitActionLabel('Dodaj')
                ->schema([
                    Select::make('template')
                        ->label('Predložak')
                        ->options($templates->optionsFor($kind))
                        ->default($templates->defaultFor($kind, PrepareVariantMedia::orientation($variant->platform)))
                        ->required()
                        ->helperText('Slajdovi moraju biti istog omjera; Instagram ostale obreže prema prvom.'),
                ])
                ->action(function (array $data) use ($id): void {
                    $variant = PostVariant::query()->find($id);

                    if ($variant === null) {
                        return;
                    }

                    try {
                        app(PrepareVariantMedia::class)->addSlide($variant, (string) $data['template']);
                    } catch (Throwable $e) {
                        Notification::make()->title('Slajd nije dodan')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Slajd se renderira')->success()->send();
                });
        }

        return $actions;
    }

    /**
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field>
     */
    private static function videoFields(PostVariant $variant): array
    {
        $tracks = $variant->draft?->brand?->audioTracks() ?? [];

        return [
            TextInput::make('seconds')->label('Sekundi po slajdu')->numeric()
                ->default(VideoRenderer::DEFAULT_SECONDS_PER_SLIDE)->minValue(1.5)->maxValue(10)->step(0.25)->required(),
            Select::make('audio')->label('Zvuk')->required()->default('auto')
                ->options([
                    'auto' => 'Automatski iz knjižnice brenda',
                    'none' => 'Bez zvuka',
                    ...collect($tracks)->mapWithKeys(fn (array $track, int $index): array => [(string) $index => $track['title']])->all(),
                ])
                ->helperText($tracks === [] ? 'Knjižnica brenda je prazna — dodaj podloge u postavkama brenda, inače video ostaje bez zvuka.' : null),
            Toggle::make('motion')->label('Pokret na slajdovima (lagani zoom)')->default(true),
        ];
    }

    private static function captionHelp(string $caption, Platform $platform, ?ContentFormat $format): string
    {
        $length = mb_strlen($caption);
        $hashtags = preg_match_all('/#[\p{L}\p{N}_]+/u', $caption);

        if ($platform === Platform::InstagramBusiness) {
            $limit = (int) config('hub.limits.ig_caption_chars', 2200);
            $maxTags = (int) config('hub.limits.ig_hashtags', 30);
            $warn = $length > $limit ? ' — PREDUGO' : '';
            $tagWarn = $hashtags > $maxTags ? ' — PREVIŠE' : '';

            return "{$length}/{$limit} znakova{$warn} · {$hashtags}/{$maxTags} hashtagova{$tagWarn} · linkovi nisu klikabilni";
        }

        if ($platform === Platform::TikTok && in_array($format, [ContentFormat::Image, ContentFormat::Carousel], true)) {
            return "{$length} znakova · prvi redak postaje naslov (do 90 znakova), cijeli tekst opis (do 4000)";
        }

        return "{$length} znakova · {$hashtags} hashtagova";
    }
}
