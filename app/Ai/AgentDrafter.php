<?php

declare(strict_types=1);

namespace App\Ai;

use App\Actions\CreateDraft;
use App\Drafting\CaptionBuilder;
use App\Enums\ActorType;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Models\AgentRun;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Notifications\DraftsAwaitingApproval;
use App\Rendering\TemplateRegistry;
use App\Support\AdminNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily pass: take the candidates nobody has drafted yet, have the model write the captions,
 * check them, and leave the result waiting for a human.
 *
 * The agent never publishes. It produces drafts in `pending_approval`; auto-publish is a separate,
 * explicitly enabled mechanism (see App\Actions\ApplyAutoPublishRules).
 */
final class AgentDrafter
{
    public function __construct(
        private readonly CaptionWriter $writer,
        private readonly CaptionValidator $validator,
        private readonly CaptionBuilder $fallback,
        private readonly CreateDraft $createDraft,
        private readonly TemplateRegistry $templates,
        private readonly AdminNotifier $notifier,
    ) {}

    /**
     * @return array{drafted: int, fallbacks: int, failed: int, titles: list<string>}
     */
    public function run(Brand $brand, ?ContentKind $kind = null, int $limit = 5, bool $dryRun = false): array
    {
        $accounts = SocialAccount::query()->where('brand_id', $brand->id)->active()->get();

        if ($accounts->isEmpty() && ! $dryRun) {
            throw new CaptionWriterException("Brend {$brand->name} nema nijedan aktivan kanal.");
        }

        $items = $this->candidates($brand, $kind, $limit);

        $run = AgentRun::query()->create([
            'brand_id' => $brand->id,
            'type' => 'caption-draft',
            'model' => (string) config('hub.ai.model'),
            'status' => 'running',
            'input_summary' => $items->count().' kandidata'.($kind !== null ? " ({$kind->value})" : ''),
        ]);

        $drafted = 0;
        $fallbacks = 0;
        $failed = 0;
        $titles = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $log = [];

        foreach ($items as $item) {
            try {
                [$captions, $usedFallback, $notes, $usage] = $this->captionsFor($item, $brand);
            } catch (Throwable $e) {
                $failed++;
                $log[] = ['item' => $item->external_id, 'error' => $e->getMessage()];
                Log::warning('hub.agent.item_failed', ['item' => $item->id, 'error' => $e->getMessage()]);

                continue;
            }

            $inputTokens += $usage[0];
            $outputTokens += $usage[1];

            if ($usedFallback) {
                $fallbacks++;
            }

            $log[] = [
                'item' => $item->external_id,
                'title' => $item->title,
                'fallback' => $usedFallback,
                'notes' => $notes,
                'facebook' => $captions[Platform::FacebookPage->value] ?? null,
                'instagram' => $captions[Platform::InstagramBusiness->value] ?? null,
            ];

            if ($dryRun) {
                $titles[] = $item->title;
                $drafted++;

                continue;
            }

            $this->createDraft->execute(
                item: $item,
                accounts: $accounts,
                actor: ActorType::Agent,
                templateKey: $this->templates->defaultFor($item->kind),
                captionOverrides: $captions,
                status: DraftStatus::PendingApproval,
                agentRunId: $run->id,
            );

            $drafted++;
            $titles[] = $item->title;
        }

        $run->forceFill([
            'status' => $failed > 0 && $drafted === 0 ? 'failed' : 'done',
            'output' => $log,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ])->save();

        if ($drafted > 0 && ! $dryRun) {
            $this->notifier->notify(new DraftsAwaitingApproval($brand, $titles));
        }

        return ['drafted' => $drafted, 'fallbacks' => $fallbacks, 'failed' => $failed, 'titles' => $titles];
    }

    /**
     * Captions for one item: ask, check, ask once more with the complaint, then fall back to the
     * deterministic builder rather than publishing something unverifiable.
     *
     * @return array{0: array<string, string>, 1: bool, 2: list<string>, 3: array{0: int, 1: int}}
     */
    private function captionsFor(ContentItem $item, Brand $brand): array
    {
        $violations = [];
        $notes = [];
        $inputTokens = 0;
        $outputTokens = 0;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $this->writer->write($item, $brand, $violations);
            $inputTokens += $result->inputTokens;
            $outputTokens += $result->outputTokens;

            $violations = $this->validator->validate($result->captions, $item);

            if (filled($result->captions->note)) {
                $notes[] = (string) $result->captions->note;
            }

            if ($violations === []) {
                $hashtags = implode(' ', $result->captions->hashtagList());

                return [
                    [
                        Platform::FacebookPage->value => mb_trim($result->captions->facebook),
                        Platform::InstagramBusiness->value => mb_trim($result->captions->instagram."\n\n".$hashtags),
                        Platform::FacebookGroup->value => mb_trim($result->captions->facebook),
                    ],
                    false,
                    $notes,
                    [$inputTokens, $outputTokens],
                ];
            }

            $notes[] = 'Odbačeno: '.implode(' ', $violations);
        }

        Log::warning('hub.agent.fallback', ['item' => $item->id, 'violations' => $violations]);

        return [$this->deterministic($item, $brand), true, $notes, [$inputTokens, $outputTokens]];
    }

    /**
     * @return array<string, string>
     */
    private function deterministic(ContentItem $item, Brand $brand): array
    {
        return [
            Platform::FacebookPage->value => $this->fallback->facebook($item, $brand),
            Platform::InstagramBusiness->value => $this->fallback->instagram($item, $brand),
            Platform::FacebookGroup->value => $this->fallback->facebook($item, $brand),
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ContentItem>
     */
    private function candidates(Brand $brand, ?ContentKind $kind, int $limit): \Illuminate\Database\Eloquent\Collection
    {
        return ContentItem::query()
            ->where('brand_id', $brand->id)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind->value))
            ->live()
            ->notDrafted()
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->limit(max(1, $limit))
            ->get();
    }
}
