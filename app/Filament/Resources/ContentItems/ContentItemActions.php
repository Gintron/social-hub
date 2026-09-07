<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentItems;

use App\Actions\CreateDraft;
use App\Enums\ActorType;
use App\Enums\DraftStatus;
use App\Filament\Resources\PostDrafts\PostDraftResource;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Rendering\TemplateRegistry;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

final class ContentItemActions
{
    /**
     * Turn a candidate into a draft (one variant per selected account, image rendered in the background).
     */
    public static function createDraft(): Action
    {
        return Action::make('create_draft')
            ->label('Napravi objavu')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('primary')
            ->modalHeading(fn (ContentItem $record): string => 'Nova objava: '.$record->title)
            ->schema([
                CheckboxList::make('accounts')
                    ->label('Kanali')
                    ->options(fn (ContentItem $record): array => SocialAccount::query()
                        ->where('brand_id', $record->brand_id)
                        ->active()
                        ->orderBy('platform')
                        ->get()
                        ->mapWithKeys(fn (SocialAccount $account): array => [$account->id => $account->platform->label().' · '.$account->name])
                        ->all())
                    ->default(fn (ContentItem $record): array => SocialAccount::query()->where('brand_id', $record->brand_id)->active()->pluck('id')->all())
                    ->required()
                    ->columns(1),
                Select::make('template')
                    ->label('Predložak slike')
                    ->options(fn (ContentItem $record): array => app(TemplateRegistry::class)->optionsFor($record->kind))
                    ->default(fn (ContentItem $record): string => app(TemplateRegistry::class)->defaultFor($record->kind))
                    ->required(),
                DateTimePicker::make('scheduled_at')
                    ->label('Zakaži za (opcionalno)')
                    ->timezone('Europe/Zagreb')
                    ->seconds(false)
                    ->minDate(now()),
                Toggle::make('approve')
                    ->label('Odmah odobri (preskoči pregled)')
                    ->default(false),
            ])
            ->action(function (array $data, ContentItem $record, $livewire): void {
                $accounts = SocialAccount::query()->whereIn('id', $data['accounts'] ?? [])->get();
                $scheduledAt = filled($data['scheduled_at'] ?? null) ? CarbonImmutable::parse((string) $data['scheduled_at'], 'Europe/Zagreb')->utc() : null;

                try {
                    $draft = app(CreateDraft::class)->execute(
                        item: $record,
                        accounts: $accounts,
                        actor: ActorType::Human,
                        actorId: auth()->id(),
                        templateKey: (string) $data['template'],
                        scheduledAt: $scheduledAt,
                        status: ($data['approve'] ?? false) ? DraftStatus::Approved : DraftStatus::PendingApproval,
                    );
                } catch (Throwable $e) {
                    Notification::make()->title('Nacrt nije napravljen')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Nacrt napravljen')->body('Slika se renderira u pozadini; otvori nacrt za pregled.')->success()->send();

                $livewire->redirect(PostDraftResource::getUrl('edit', ['record' => $draft]));
            });
    }
}
