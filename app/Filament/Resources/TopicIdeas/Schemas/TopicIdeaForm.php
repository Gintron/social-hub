<?php

declare(strict_types=1);

namespace App\Filament\Resources\TopicIdeas\Schemas;

use App\Enums\TopicStatus;
use App\Models\TopicIdea;
use App\Persona\ShortScript;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class TopicIdeaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Tema')
                    ->schema([
                        Placeholder::make('topic_title')->label('Tema')->content(fn (?TopicIdea $record): string => (string) $record?->title),
                        Placeholder::make('topic_hook')->label('Udica')->content(fn (?TopicIdea $record): string => (string) $record?->hook),
                        Placeholder::make('topic_angle')->label('O čemu video govori')->content(fn (?TopicIdea $record): string => (string) $record?->angle),
                        Placeholder::make('topic_reason')->label('Zašto baš ova')->content(fn (?TopicIdea $record): string => (string) $record?->reason)
                            ->visible(fn (?TopicIdea $record): bool => filled($record?->reason)),
                        Placeholder::make('topic_error')->label('Zašto skripta nije napisana')
                            ->content(fn (?TopicIdea $record): string => (string) $record?->script_error)
                            ->visible(fn (?TopicIdea $record): bool => filled($record?->script_error)),
                    ]),

                Section::make('Video')
                    ->visible(fn (?TopicIdea $record): bool => in_array($record?->status, [TopicStatus::Producing, TopicStatus::Produced, TopicStatus::VideoFailed], true))
                    ->schema([
                        Placeholder::make('video_state')->label('Stanje')
                            ->content(fn (?TopicIdea $record): string => match ($record?->status) {
                                TopicStatus::Producing => 'Glas i kadrovi se rade; fal treba nekoliko minuta. Ova stranica se ne osvježava sama.',
                                TopicStatus::Produced => 'Video je u objavi koja čeka odobrenje (gumb „Otvori objavu“ gore).',
                                default => 'Video nije napravljen.',
                            }),
                        Placeholder::make('video_error')->label('Zašto')
                            ->content(fn (?TopicIdea $record): string => (string) $record?->production_error)
                            ->visible(fn (?TopicIdea $record): bool => filled($record?->production_error)),
                    ]),

                Section::make('Skripta')
                    ->description('Što lik govori, redak po redak, i što se vidi dok to govori. Pročitaj naglas: tako će zvučati. Spremi izmjene pa odobri; glas i video nastaju samo iz odobrene skripte. Uzastopni redci istog kadra postaju jedan kadar.')
                    ->visible(fn (?TopicIdea $record): bool => $record?->status->hasScript() ?? false)
                    ->schema([
                        Placeholder::make('script_length')->label('Trajanje (procjena)')
                            ->content(fn (?TopicIdea $record): string => self::length($record)),
                        TextInput::make('script.title')->label('Naslov (YouTube)')->required()->maxLength(100),
                        Repeater::make('script.lines')->label('Redci')
                            ->schema([
                                Textarea::make('text')->label('Lik govori')->rows(2)->required(),
                                Select::make('shot')->label('Kadar')->options(ShortScript::SHOTS)->default('talking')
                                    ->selectablePlaceholder(false),
                                Textarea::make('visual')->label('Što se vidi')->rows(2)
                                    ->helperText('Za međukadar: scena za video model, bez teksta, logotipa i stvarnih osoba.'),
                            ])
                            ->minItems(1)->maxItems(ShortScript::MAX_LINES)
                            ->reorderableWithButtons()
                            ->addActionLabel('Dodaj redak')
                            ->itemLabel(fn (array $state): ?string => isset($state['text']) ? mb_strimwidth((string) $state['text'], 0, 70, '…') : null),
                        Textarea::make('script.caption')->label('Tekst objave')->rows(4)
                            ->helperText('Bez poveznica i bez ičega što skripta ne kaže.'),
                        TextInput::make('script.hashtags')->label('Hashtagovi')->maxLength(200),
                    ]),
            ]);
    }

    private static function length(?TopicIdea $record): string
    {
        $script = $record?->shortScript();

        if ($script === null) {
            return '—';
        }

        $seconds = $script->estimatedSeconds($record->brand->personaSettings()->language);
        $max = (int) config('hub.persona.max_seconds', 60);

        return "oko {$seconds} s".($seconds > $max ? " — dulje od {$max} s koliko short smije trajati" : '').' · '.count($script->lines).' redaka';
    }
}
