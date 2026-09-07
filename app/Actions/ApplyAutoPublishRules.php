<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActorType;
use App\Enums\DraftStatus;
use App\Models\AutoPublishRule;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns freshly synced items into scheduled posts, for the sources and platforms where a human has
 * switched automation on.
 *
 * Off by default and per source × platform: a brand can let its deals post themselves while its
 * job listings still go through review.
 */
final class ApplyAutoPublishRules
{
    public function __construct(
        private readonly CreateDraft $createDraft,
        private readonly PostingSchedule $schedule,
    ) {}

    /**
     * @return int How many drafts were created
     */
    public function execute(Source $source): int
    {
        $rules = $source->autoPublishRules()->where('enabled', true)->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $accounts = SocialAccount::query()
            ->where('brand_id', $source->brand_id)
            ->active()
            ->whereIn('platform', $rules->pluck('platform')->map(fn ($platform): string => $platform->value)->all())
            ->get();

        if ($accounts->isEmpty()) {
            Log::warning('hub.autopublish.no_accounts', ['source' => $source->name]);

            return 0;
        }

        $capacity = $this->capacity($source, $rules);

        if ($capacity <= 0) {
            return 0;
        }

        $items = ContentItem::query()
            ->where('source_id', $source->id)
            ->live()
            ->notDrafted()
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->limit($capacity)
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $delay = (int) $rules->max('delay_minutes');
        $slots = $this->schedule->slots($source->brand, CarbonImmutable::now()->addMinutes($delay), $items->count());

        $created = 0;

        foreach ($items as $index => $item) {
            try {
                $this->createDraft->execute(
                    item: $item,
                    accounts: $accounts,
                    actor: ActorType::System,
                    scheduledAt: $slots[$index],
                    status: DraftStatus::Approved,
                );
                $created++;
            } catch (Throwable $e) {
                Log::warning('hub.autopublish.draft_failed', ['item' => $item->id, 'error' => $e->getMessage()]);
            }
        }

        Log::info('hub.autopublish', ['source' => $source->name, 'created' => $created]);

        return $created;
    }

    /**
     * How many more posts this source may schedule today, honouring the strictest cap among its
     * enabled rules. A rule without a cap does not limit anything.
     *
     * @param  \Illuminate\Support\Collection<int, AutoPublishRule>  $rules
     */
    private function capacity(Source $source, $rules): int
    {
        $caps = $rules->pluck('daily_cap')->filter()->all();

        if ($caps === []) {
            return 25;
        }

        $cap = (int) min($caps);

        $usedToday = PostDraft::query()
            ->where('created_by_type', ActorType::System->value)
            ->whereHas('contentItems', fn ($query) => $query->where('source_id', $source->id))
            ->where('created_at', '>=', CarbonImmutable::now()->startOfDay())
            ->count();

        return max(0, $cap - $usedToday);
    }
}
