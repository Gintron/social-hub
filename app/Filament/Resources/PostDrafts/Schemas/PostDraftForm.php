<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostDrafts\Schemas;

use App\Enums\Platform;
use App\Filament\Support\FormState;
use App\Models\ContentItem;
use App\Models\PostDraft;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The review screen: one draft, its source item(s), and an editable variant per channel with a live preview.
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
                        .'. Provjeri tekst i renderiraj sliku ponovno prije objave.'),

                Section::make('Objava')
                    ->schema([
                        TextInput::make('title')->label('Interni naslov')->maxLength(300),
                        Placeholder::make('status_info')->label('Status')
                            ->content(fn (?PostDraft $record): string => $record?->status->label() ?? '—'),
                        DateTimePicker::make('scheduled_at')->label('Zakazano za')->timezone('Europe/Zagreb')->seconds(false)
                            ->helperText('Sprema se s obrascem; za zakazivanje koristi akciju "Zakaži" gore.'),
                        Placeholder::make('items_info')->label('Stavke')
                            ->content(fn (?PostDraft $record): HtmlString => new HtmlString(
                                $record?->contentItems->map(fn (ContentItem $item): string => sprintf(
                                    '<a href="%s" target="_blank" rel="noopener" class="underline">%s</a> <span style="opacity:.6">(%s%s)</span>',
                                    e($item->url), e($item->title), e($item->kind->label()),
                                    $item->expires_at ? ', istječe '.$item->expires_at->timezone('Europe/Zagreb')->format('d.m.Y') : ''
                                ))->implode('<br>') ?: '—'
                            )),
                        Textarea::make('notes')->label('Bilješke')->rows(2)->columnSpanFull(),
                    ])->columns(2),

                Section::make('Varijante po kanalu')
                    ->description('Uredi tekst za svaki kanal. Pregled desno pokazuje kako će objava izgledati; za Facebook grupu kopiraj tekst i preuzmi sliku.')
                    ->schema([
                        Repeater::make('variants')
                            ->label('')
                            ->relationship()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => FormState::platform($state['platform'] ?? null)?->label() ?? 'Kanal')
                            ->schema([
                                Toggle::make('enabled')
                                    ->label('Objavi na ovaj kanal')
                                    ->inline(false)
                                    ->columnSpanFull(),
                                Textarea::make('caption')
                                    ->label('Tekst objave')
                                    ->rows(16)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->helperText(fn (?string $state, Get $get): string => self::captionHelp((string) $state, FormState::platform($get('platform'))))
                                    ->columnSpan(1),
                                ViewField::make('preview')
                                    ->label('Pregled')
                                    ->view('filament.forms.variant-preview')
                                    ->dehydrated(false)
                                    ->columnSpan(1),
                                Select::make('settings.mode')
                                    ->label('Način objave')
                                    ->options([
                                        'photo' => 'Foto-post (slika + tekst)',
                                        'link' => 'Link-post (tekst + pregled linka)',
                                        'reel' => 'Reel (video 9:16, 3–90 s)',
                                    ])
                                    ->default('photo')
                                    ->visible(fn (Get $get): bool => FormState::platform($get('platform')) === Platform::FacebookPage),

                                Select::make('settings.format')
                                    ->label('Format')
                                    ->options(['post' => 'Objava (slika ili carousel)', 'reel' => 'Reel (video 9:16)'])
                                    ->default('post')
                                    ->visible(fn (Get $get): bool => FormState::platform($get('platform')) === Platform::InstagramBusiness),

                                Select::make('settings.privacy_level')
                                    ->label('Vidljivost na TikToku')
                                    ->options([
                                        'SELF_ONLY' => 'Samo ja (obavezno dok app nije auditirana)',
                                        'PUBLIC_TO_EVERYONE' => 'Javno',
                                        'MUTUAL_FOLLOW_FRIENDS' => 'Prijatelji',
                                        'FOLLOWER_OF_CREATOR' => 'Pratitelji',
                                    ])
                                    ->default('SELF_ONLY')
                                    ->helperText('TikTok dopušta samo razine koje sam vrati za taj račun; hub pada natrag na dopuštenu.')
                                    ->visible(fn (Get $get): bool => FormState::platform($get('platform')) === Platform::TikTok),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    private static function captionHelp(string $caption, ?Platform $platform): string
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

        return "{$length} znakova · {$hashtags} hashtagova";
    }
}
