<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostDrafts\Pages;

use App\Actions\ApproveDraft;
use App\Actions\DiscardDraft;
use App\Actions\DispatchDraftPublishing;
use App\Actions\MarkManualPosted;
use App\Actions\ScheduleDraft;
use App\Actions\UpdateVariant;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Filament\Resources\PostDrafts\PostDraftResource;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Publishing\FormatCheck;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Review screen: a tab per channel (format, media, text) and one primary action.
 *
 * "Spremi i objavi", "Zakaži" and "Odobri" save the form first. They used to act on what was in
 * the database, so a caption edited a moment earlier went out in its old wording.
 */
final class EditPostDraft extends EditRecord
{
    protected static string $resource = PostDraftResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $draft = $this->draft();

        $status = $draft->status->label().($draft->scheduled_at !== null
            ? ' · zakazano za '.$draft->scheduled_at->timezone('Europe/Zagreb')->format('d.m.Y H:i')
            : '');

        $items = $draft->contentItems->map(fn (ContentItem $item): string => sprintf(
            '<a href="%s" target="_blank" rel="noopener" class="underline">%s</a> (%s%s)',
            e($item->url),
            e($item->title),
            e($item->kind->label()),
            $item->expires_at ? ', istječe '.$item->expires_at->timezone('Europe/Zagreb')->format('d.m.Y') : '',
        ))->implode(' · ');

        return new HtmlString(e($status).($items !== '' ? ' — '.$items : ''));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('schedule')
                ->label('Zakaži')
                ->icon(Heroicon::OutlinedCalendar)
                ->color('gray')
                ->visible(fn (): bool => ! $this->draft()->status->isTerminal() && $this->draft()->status !== DraftStatus::Publishing)
                ->schema([
                    DateTimePicker::make('scheduled_at')->label('Objavi u')->timezone('Europe/Zagreb')->seconds(false)->required()
                        ->default(fn (): CarbonImmutable => ($this->draft()->scheduled_at ?? now()->addHour()->toImmutable())->timezone('Europe/Zagreb')),
                ])
                ->action(function (array $data): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    $at = CarbonImmutable::parse((string) $data['scheduled_at'], 'Europe/Zagreb')->utc();
                    $this->run(fn () => app(ScheduleDraft::class)->execute($this->draft(), $at, auth()->id()), 'Spremljeno i zakazano za '.$at->timezone('Europe/Zagreb')->format('d.m.Y H:i').'.');
                }),

            Action::make('publish_now')
                ->label('Spremi i objavi')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Objaviti sada?')
                ->modalDescription(fn (): HtmlString => $this->publishDescription())
                ->modalSubmitActionLabel('Objavi')
                ->visible(fn (): bool => ! in_array($this->draft()->status, [DraftStatus::Publishing, DraftStatus::Published, DraftStatus::Discarded], true))
                ->action(function (): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    $this->run(function (): string {
                        $draft = $this->draft();

                        if (in_array($draft->status, [DraftStatus::Draft, DraftStatus::PendingApproval], true)) {
                            app(ApproveDraft::class)->execute($draft, auth()->id());
                        }

                        $queued = app(DispatchDraftPublishing::class)->execute($draft->refresh());

                        return "Spremljeno; {$queued} kanal(a) u redu za objavu.";
                    });
                }),

            ActionGroup::make([
                Action::make('approve')
                    ->label('Odobri bez objave')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (): bool => in_array($this->draft()->status, [DraftStatus::Draft, DraftStatus::PendingApproval], true))
                    ->action(function (): void {
                        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                        $this->run(fn () => app(ApproveDraft::class)->execute($this->draft(), auth()->id()), 'Spremljeno i odobreno.');
                    }),

                Action::make('mark_manual')
                    ->label('Označi kao ručno objavljeno')
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->visible(fn (): bool => $this->manualVariants()->isNotEmpty())
                    ->schema([
                        Select::make('variant_id')->label('Kanal')->required()
                            ->options(fn (): array => $this->manualVariants()->mapWithKeys(fn (PostVariant $v): array => [$v->id => $v->account?->name ?? $v->platform->label()])->all()),
                        TextInput::make('permalink')->label('Link na objavu (opcionalno)')->url(),
                    ])
                    ->action(function (array $data): void {
                        $variant = PostVariant::query()->where('post_draft_id', $this->draft()->id)->findOrFail((int) $data['variant_id']);
                        $this->run(fn () => app(MarkManualPosted::class)->execute($variant, auth()->id(), $data['permalink'] ?: null), 'Označeno kao objavljeno.');
                    }),

                Action::make('discard')
                    ->label('Odbaci nacrt')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => ! in_array($this->draft()->status, [DraftStatus::Publishing, DraftStatus::Published, DraftStatus::Discarded], true))
                    ->action(function (): void {
                        try {
                            app(DiscardDraft::class)->execute($this->draft());
                        } catch (Throwable $e) {
                            Notification::make()->title('Nije odbačeno')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Nacrt odbačen')->success()->send();
                        $this->redirect(PostDraftResource::getUrl('index'));
                    }),
            ])
                ->label('Više')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['channels'] = $this->draft()->variants()->with('media')->get()
            ->mapWithKeys(fn (PostVariant $variant): array => ["v{$variant->id}" => [
                'enabled' => $variant->enabled,
                'format' => $variant->format()->value,
                'caption' => $variant->caption,
                'delivery' => (string) $variant->setting('delivery', 'direct'),
                'privacy_level' => (string) $variant->setting('privacy_level', config('tiktok.default_privacy_level', 'SELF_ONLY')),
                'auto_add_music' => (bool) $variant->setting('auto_add_music', true),
            ]])
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PostDraft $record */
        $channels = (array) Arr::pull($data, 'channels', []);

        $record->update(Arr::only($data, ['title', 'notes']));

        foreach ($record->variants()->get() as $variant) {
            $state = $channels["v{$variant->id}"] ?? null;

            // Locked channels render their fields disabled, so they never come back in the state.
            if (! is_array($state) || $variant->isLocked()) {
                continue;
            }

            app(UpdateVariant::class)->execute(
                $variant,
                caption: array_key_exists('caption', $state) ? (string) $state['caption'] : null,
                enabled: array_key_exists('enabled', $state) ? (bool) $state['enabled'] : null,
                settings: Arr::only($state, ['delivery', 'privacy_level', 'auto_add_music']),
            );
        }

        return $record;
    }

    private function draft(): PostDraft
    {
        /** @var PostDraft $record */
        $record = $this->getRecord();

        return $record;
    }

    /**
     * What will happen, what is not ready yet, and TikTok's music declaration (its Direct Post
     * guidelines require it before the user posts).
     */
    private function publishDescription(): HtmlString
    {
        $variants = $this->draft()->variants()->with(['account', 'media'])->get()
            ->filter(fn (PostVariant $variant): bool => $variant->enabled && ! $variant->isLocked());

        $parts = [e('Promjene se prvo spremaju. Kanali preko API-ja idu u red za objavu; Facebook grupe i TikTok inbox čekaju da ih dovršiš i označiš.')];

        $checks = app(FormatCheck::class);
        $problems = $variants
            ->reject(fn (PostVariant $variant): bool => $variant->platform->isManual())
            ->map(fn (PostVariant $variant): ?string => ($problem = $checks->problem($variant)) === null ? null : e($variant->platform->label().': '.$problem))
            ->filter();

        if ($problems->isNotEmpty()) {
            $parts[] = '<strong>Nije spremno:</strong><br>'.$problems->implode('<br>')
                .'<br>'.e('Kanal kojem se medij još renderira pričeka ga; ostali padnu s tim razlogom.');
        }

        if ($variants->contains(fn (PostVariant $variant): bool => $variant->platform === Platform::TikTok && $variant->setting('delivery') !== 'inbox')) {
            $parts[] = 'Objavom na TikTok pristaješ na TikTokovu '
                .'<a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener" class="underline">Music Usage Confirmation</a>.';
        }

        return new HtmlString(implode('<br><br>', $parts));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PostVariant>
     */
    private function manualVariants(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->draft()->variants()->with('account')->get()
            // Facebook groups, plus anything handed to a human mid-way (a TikTok inbox upload).
            ->filter(fn (PostVariant $v): bool => $v->status === VariantStatus::ManualPending
                || ($v->platform->isManual() && ! in_array($v->status, [VariantStatus::ManualDone, VariantStatus::Disabled], true)))
            ->values();
    }

    /**
     * @param  callable(): (string|mixed)  $callback
     */
    private function run(callable $callback, ?string $successMessage = null): void
    {
        try {
            $result = $callback();
        } catch (Throwable $e) {
            Notification::make()->title('Akcija nije uspjela')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title($successMessage ?? (is_string($result) ? $result : 'Gotovo.'))->success()->send();

        $this->redirect(PostDraftResource::getUrl('edit', ['record' => $this->draft()]));
    }
}
