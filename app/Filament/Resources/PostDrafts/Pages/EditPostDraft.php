<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostDrafts\Pages;

use App\Actions\ApproveDraft;
use App\Actions\DiscardDraft;
use App\Actions\DispatchDraftPublishing;
use App\Actions\MarkManualPosted;
use App\Actions\ScheduleDraft;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Filament\Resources\PostDrafts\PostDraftResource;
use App\Jobs\RenderMediaJob;
use App\Jobs\RenderVideoJob;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Rendering\TemplateRegistry;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use RuntimeException;
use Throwable;

/**
 * Review screen: edit captions (form), then approve / schedule / publish / regenerate / discard (header actions).
 */
final class EditPostDraft extends EditRecord
{
    protected static string $resource = PostDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Odobri')
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn (): bool => in_array($this->draft()->status, [DraftStatus::Draft, DraftStatus::PendingApproval], true))
                ->action(function (): void {
                    $this->run(fn () => app(ApproveDraft::class)->execute($this->draft(), auth()->id()), 'Odobreno.');
                }),

            Action::make('schedule')
                ->label('Zakaži')
                ->icon(Heroicon::OutlinedCalendar)
                ->color('info')
                ->visible(fn (): bool => ! $this->draft()->status->isTerminal() && $this->draft()->status !== DraftStatus::Publishing)
                ->schema([
                    DateTimePicker::make('scheduled_at')->label('Objavi u')->timezone('Europe/Zagreb')->seconds(false)->required()
                        ->default(fn (): CarbonImmutable => ($this->draft()->scheduled_at ?? now()->addHour()->toImmutable())->timezone('Europe/Zagreb')),
                ])
                ->action(function (array $data): void {
                    $at = CarbonImmutable::parse((string) $data['scheduled_at'], 'Europe/Zagreb')->utc();
                    $this->run(fn () => app(ScheduleDraft::class)->execute($this->draft(), $at, auth()->id()), 'Zakazano za '.$at->timezone('Europe/Zagreb')->format('d.m.Y H:i').'.');
                }),

            Action::make('publish_now')
                ->label('Objavi sada')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(fn (): HtmlString|string => $this->publishDescription())
                ->visible(fn (): bool => ! in_array($this->draft()->status, [DraftStatus::Publishing, DraftStatus::Published, DraftStatus::Discarded], true))
                ->action(function (): void {
                    $this->run(function (): string {
                        $draft = $this->draft();

                        if (in_array($draft->status, [DraftStatus::Draft, DraftStatus::PendingApproval], true)) {
                            app(ApproveDraft::class)->execute($draft, auth()->id());
                        }

                        $queued = app(DispatchDraftPublishing::class)->execute($draft->refresh());

                        return "{$queued} varijant(a) poslano u red za objavu.";
                    });
                }),

            Action::make('regenerate')
                ->label('Renderiraj sliku ponovno')
                ->icon(Heroicon::OutlinedPhoto)
                ->color('gray')
                ->visible(fn (): bool => ! $this->draft()->status->isTerminal())
                ->schema([
                    Select::make('template')->label('Predložak')->required()
                        ->options(fn (): array => app(TemplateRegistry::class)->optionsFor($this->draft()->contentItems->first()?->kind))
                        ->default(fn (): ?string => $this->draft()->mediaAssets()->latest('id')->value('template_key')
                            ?? ($this->draft()->contentItems->first() ? app(TemplateRegistry::class)->defaultFor($this->draft()->contentItems->first()->kind) : null)),
                    Select::make('mode')
                        ->label('Način')
                        ->options([
                            'replace' => 'Zamijeni postojeću sliku',
                            'append' => 'Dodaj kao sljedeći slajd (Instagram carousel)',
                        ])
                        ->default('replace')
                        ->required()
                        ->helperText('Carousel prima do 10 slajdova; svi moraju biti istog omjera.'),
                    KeyValue::make('overrides')->label('Nadjačaj polja (opcionalno)')->keyLabel('Polje')->valueLabel('Vrijednost')
                        ->helperText('npr. title, subtitle, excerpt — mijenja samo ovu sliku, ne stavku.'),
                ])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $draft = $this->draft();
                        $item = $draft->contentItems->first();

                        if ($item === null) {
                            throw new RuntimeException('Nacrt nema stavku iz koje bi se renderirala slika.');
                        }

                        $replace = ($data['mode'] ?? 'replace') === 'replace';

                        RenderMediaJob::dispatchSync(
                            $draft->id,
                            $item->id,
                            (string) $data['template'],
                            $draft->variants()->pluck('id')->all(),
                            array_filter($data['overrides'] ?? []),
                            $replace,
                        );

                        return $replace ? 'Slika renderirana.' : 'Slajd dodan.';
                    });
                }),

            Action::make('render_video')
                ->label('Renderiraj video')
                ->icon(Heroicon::OutlinedVideoCamera)
                ->color('gray')
                ->visible(fn (): bool => ! $this->draft()->status->isTerminal())
                ->modalDescription('Renderira uspravne slajdove (9:16) i spaja ih u MP4 za Reels i TikTok. Traje desetak sekundi po slajdu.')
                ->schema([
                    TextInput::make('seconds')->label('Sekundi po slajdu')->numeric()->default(3)->minValue(2)->maxValue(10)->required(),
                    Select::make('audio')->label('Zvuk')->required()->default('auto')
                        ->options(fn (): array => [
                            'auto' => 'Automatski iz knjižnice brenda',
                            'none' => 'Bez zvuka',
                            ...collect($this->draft()->brand->audioTracks())
                                ->mapWithKeys(fn (array $track, int $index): array => [(string) $index => $track['title']])
                                ->all(),
                        ])
                        ->helperText(fn (): ?string => $this->draft()->brand->audioTracks() === []
                            ? 'Knjižnica brenda je prazna — dodaj podloge u postavkama brenda, inače video ostaje bez zvuka.'
                            : null),
                    Toggle::make('motion')->label('Pokret na slajdovima (lagani zoom)')->default(true),
                ])
                ->action(function (array $data): void {
                    $this->run(function () use ($data): string {
                        $draft = $this->draft();

                        RenderVideoJob::dispatchSync(
                            $draft->id,
                            $draft->variants()->pluck('id')->all(),
                            (float) $data['seconds'],
                            audio: (string) ($data['audio'] ?? 'auto'),
                            motion: (bool) ($data['motion'] ?? true),
                        );

                        return 'Video renderiran i priložen svim kanalima ovog nacrta.';
                    });
                }),

            Action::make('mark_manual')
                ->label('Označi kao ručno objavljeno')
                ->icon(Heroicon::OutlinedClipboardDocument)
                ->color('warning')
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
                ->label('Odbaci')
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
        ];
    }

    private function draft(): PostDraft
    {
        /** @var PostDraft $record */
        $record = $this->getRecord();

        return $record;
    }

    /**
     * TikTok's Direct Post guidelines require this declaration before the user posts.
     */
    private function publishDescription(): HtmlString|string
    {
        $text = 'Varijante s API kanalima idu u red za objavu; varijante za Facebook grupe i TikTok inbox čekaju da ih ručno dovršiš i označiš.';

        $directTikTok = $this->draft()->variants()->get()->contains(
            fn (PostVariant $v): bool => $v->platform === Platform::TikTok && $v->enabled && $v->setting('delivery') !== 'inbox',
        );

        if (! $directTikTok) {
            return $text;
        }

        return new HtmlString(e($text).'<br><br>Objavom na TikTok pristaješ na TikTokovu '
            .'<a href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener" class="underline">Music Usage Confirmation</a>.');
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
