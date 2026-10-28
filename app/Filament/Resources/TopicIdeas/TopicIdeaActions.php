<?php

declare(strict_types=1);

namespace App\Filament\Resources\TopicIdeas;

use App\Actions\ApproveTopicScript;
use App\Actions\ChooseTopic;
use App\Actions\RejectTopic;
use App\Enums\TopicStatus;
use App\Jobs\SuggestTopicsJob;
use App\Models\Brand;
use App\Models\TopicIdea;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * What a person does with a topic, the same on the list and on the topic's page. Every one of them is an App\Actions
 * class; this only asks and reports.
 */
final class TopicIdeaActions
{
    public static function choose(): Action
    {
        return Action::make('choose')
            ->label('Odaberi')
            ->icon(Heroicon::OutlinedCheck)
            ->color('primary')
            ->visible(fn (TopicIdea $record): bool => $record->status->canBeChosen())
            ->action(function (TopicIdea $record): void {
                self::run(fn () => app(ChooseTopic::class)->execute($record), 'Skripta se piše', 'Za minutu-dvije je na stranici teme.');
            });
    }

    public static function rewrite(): Action
    {
        return Action::make('rewrite')
            ->label('Napiši ponovno')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (TopicIdea $record): bool => $record->status->hasScript())
            ->requiresConfirmation()
            ->modalDescription('Nova skripta zamjenjuje ovu, s tvojim izmjenama.')
            ->action(function (TopicIdea $record): void {
                self::run(fn () => app(ChooseTopic::class)->execute($record, rewrite: true), 'Skripta se piše ponovno');
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Odbaci')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->visible(fn (TopicIdea $record): bool => in_array($record->status, [TopicStatus::Proposed, TopicStatus::Failed], true))
            ->action(function (TopicIdea $record): void {
                self::run(fn () => app(RejectTopic::class)->execute($record), 'Tema je odbačena', 'Neće se ponovno predlagati.');
            });
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Odobri skriptu')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (TopicIdea $record): bool => $record->status === TopicStatus::Scripted)
            ->requiresConfirmation()
            ->modalDescription('Spremi izmjene prije odobravanja: odobrava se spremljena skripta.')
            ->action(function (TopicIdea $record): void {
                self::run(fn () => app(ApproveTopicScript::class)->execute($record, Auth::id()), 'Skripta je odobrena');
            });
    }

    public static function suggest(): Action
    {
        return Action::make('suggest')
            ->label('Predloži teme')
            ->icon(Heroicon::OutlinedSparkles)
            ->schema([
                Select::make('brand_id')->label('Lik')->required()
                    ->options(fn (): array => Brand::query()->whereNotNull('persona')->orderBy('name')->get()
                        ->filter(fn (Brand $brand): bool => $brand->personaSettings()->isReady())
                        ->mapWithKeys(fn (Brand $brand): array => [$brand->id => $brand->personaSettings()->name.' ('.$brand->name.')'])
                        ->all()),
                Toggle::make('more')->label('Još jedna serija uz današnje')->default(true)
                    ->helperText('Isključeno: samo ako lik danas još nema tema.'),
            ])
            ->action(function (array $data): void {
                SuggestTopicsJob::dispatch((int) $data['brand_id'], (bool) ($data['more'] ?? false));

                Notification::make()->title('Teme stižu')->body('Model ih piše minutu-dvije; popis se sam osvježava.')->success()->send();
            });
    }

    /**
     * @param  callable(): mixed  $action
     */
    private static function run(callable $action, string $title, ?string $body = null): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Nije moguće')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($title)->body($body)->success()->send();
    }
}
