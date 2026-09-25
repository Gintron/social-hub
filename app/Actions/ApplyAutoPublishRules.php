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
use App\Publishing\LinkPreflight;
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
 * variant settings) and how many posts a day the channel takes. Items go out highest priority
 * first, so a channel with a small cap (two Reels a day) gets the best items while a channel with
 * a large one (the Facebook groups) gets all of them.
 */
final class ApplyAutoPublishRules
{
    /**
     * A rule without a cap still stops somewhere; one sync must not schedule a whole catalogue.
     */
    public const DEFAULT_DAILY_CAP = 25;

    /**
     * Candidates looked at per post still owed today (at most CANDIDATE_POOL), so items whose page
     * is gone can be passed over without the day's posts falling short.
     */
    private const CANDIDATES_PER_POST = 8;

    private const CANDIDATE_POOL = 100;

    public function __construct(
        private readonly CreateDraft $createDraft,
        private readonly PostingSchedule $schedule,
        private readonly LinkPreflight $links,
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

        $connected = $accounts->map(fn (SocialAccount $account): string => $account->platform->value)->unique()->all();
        $remaining = array_intersect_key($this->remaining($source, $rules), array_flip($connected));
        $most = $remaining === [] ? 0 : max($remaining);

        if ($most <= 0) {
            return 0;
        }

        $items = ContentItem::query()
            ->where('source_id', $source->id)
            ->live()
            ->notDrafted()
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->limit(min(self::CANDIDATE_POOL, $most * self::CANDIDATES_PER_POST))
            ->get()
            ->lazy()
            ->filter(fn (ContentItem $item): bool => $this->links->isAlive($item))
            ->take($most)
            ->values()
            ->collect();

        if ($items->isEmpty()) {
            return 0;
        }

        $delay = (int) $rules->max('delay_minutes');
        $after = $this->schedule->queueAfter($source->brand, CarbonImmutable::now()->addMinutes($delay));
        $slots = $this->schedule->slots($source->brand, $after, $items->count());
        $options = $rules->mapWithKeys(fn (AutoPublishRule $rule): array => [$rule->platform->value => $rule->channelOptions()])->all();

        $created = 0;

        foreach ($items as $item) {
            $open = array_keys(array_filter($remaining, fn (int $left): bool => $left > 0));

            if ($open === []) {
                break;
            }

            try {
                $this->createDraft->execute(
                    item: $item,
                    accounts: $accounts->filter(fn (SocialAccount $account): bool => in_array($account->platform->value, $open, true))->values(),
                    actor: ActorType::System,
                    scheduledAt: $slots[$created],
                    status: DraftStatus::Approved,
                    channelOptions: $options,
                );

                foreach ($open as $platform) {
                    $remaining[$platform]--;
                }

                $created++;
            } catch (Throwable $e) {
                Log::warning('hub.autopublish.draft_failed', ['item' => $item->id, 'error' => $e->getMessage()]);
            }
        }

        Log::info('hub.autopublish', ['source' => $source->name, 'created' => $created]);

        return $created;
    }

    /**
     * The daily pass for sources that asked for it: schedule the live items the daily caps held
     * back on earlier days. Without it an item that arrived over a cap would never be posted,
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
     * How many more single-item posts each channel of this source may take today. Digests do not
     * count: they collect items that already had their own post.
     *
     * @param  Collection<int, AutoPublishRule>  $rules
     * @return array<string, int> platform value => posts left today
     */
    private function remaining(Source $source, Collection $rules): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $remaining = [];

        foreach ($rules as $rule) {
            $used = PostDraft::query()
                ->where('created_by_type', ActorType::System->value)
                ->where('kind', PostDraft::KIND_SINGLE)
                ->where('created_at', '>=', $today)
                ->whereHas('contentItems', fn ($query) => $query->where('source_id', $source->id))
                ->whereHas('variants', fn ($query) => $query->where('platform', $rule->platform->value))
                ->count();

            $remaining[$rule->platform->value] = max(0, ($rule->daily_cap ?? self::DEFAULT_DAILY_CAP) - $used);
        }

        return $remaining;
    }
}
