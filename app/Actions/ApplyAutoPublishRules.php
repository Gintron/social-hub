<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActorType;
use App\Enums\DraftStatus;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns freshly synced items into scheduled posts, for the sources and platforms where a human has
 * switched automation on.
 *
 * Off by default and per source × platform: a brand can let its deals post themselves while its
 * job listings still go through review. Each rule also says how its channel posts (format and
 * variant settings), so the same item goes out as a Reel on Instagram and a photo post on TikTok.
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
        $after = $this->startAfter($source->brand, CarbonImmutable::now()->addMinutes($delay));
        $slots = $this->schedule->slots($source->brand, $after, $items->count());
        $options = $rules->mapWithKeys(fn (AutoPublishRule $rule): array => [$rule->platform->value => $rule->channelOptions()])->all();

        $created = 0;

        foreach ($items as $index => $item) {
            try {
                $this->createDraft->execute(
                    item: $item,
                    accounts: $accounts,
                    actor: ActorType::System,
                    scheduledAt: $slots[$index],
                    status: DraftStatus::Approved,
                    channelOptions: $options,
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
     * The daily pass for sources that asked for it: schedule the live items the daily cap held
     * back on earlier days. Without it an item that arrived over the cap would never be posted,
     * because the rules otherwise only run when a sync brings something new.
     *
     * @return int How many drafts were created
     */
    public function backlog(Source $source): int
    {
        if (! $source->auto_publish_backlog) {
            return 0;
        }

        return $this->execute($source);
    }

    /**
     * How many more posts this source may schedule today, honouring the strictest cap among its
     * enabled rules. A rule without a cap does not limit anything.
     *
     * @param  Collection<int, AutoPublishRule>  $rules
     */
    private function capacity(Source $source, Collection $rules): int
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

    /**
     * Behind the posts automation has already lined up for this brand, so a second batch — the
     * morning backlog, then a sync at noon — queues after the first instead of taking its slots.
     */
    private function startAfter(Brand $brand, CarbonImmutable $earliest): CarbonImmutable
    {
        $last = PostDraft::query()
            ->where('brand_id', $brand->id)
            ->where('created_by_type', ActorType::System->value)
            ->where('status', DraftStatus::Scheduled->value)
            ->where('scheduled_at', '>=', $earliest)
            ->max('scheduled_at');

        return $last === null
            ? $earliest
            : CarbonImmutable::parse((string) $last, 'UTC')->addMinutes(PostingSchedule::SPACING_MINUTES);
    }
}
