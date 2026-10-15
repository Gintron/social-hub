<?php

declare(strict_types=1);

namespace App\Actions;

use App\Catalog\CatalogDemo;
use App\Enums\ActorType;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Publishing\LinkPreflight;
use App\Publishing\RestrictedGoodsCheck;
use App\Support\DailyPostLimit;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The brand's automatic posts, one at a time, in its posting windows: each post takes the first kind in the
 * brand's order that has an item waiting (by default a catalog, then a comparison, then a deal).
 *
 * The daily limit (`daily_post_limit`) is the number of posts a day, shared out over the windows (see quota()).
 * A window is filled from `LEAD_MINUTES` before it opens, so the candidates are the ones the sources have
 * delivered by then, and the first post goes out at the window's start (or at once, if the window is already
 * open). A post that is planned stays planned: a catalog that arrives later waits for the next free slot rather
 * than pushing out a post that is already in the queue.
 *
 * Only brands with `auto_lineup` on use this. `ApplyAutoPublishRules` keeps its own behaviour for
 * the rest, and the rules of each source still say which channels and formats it posts to.
 */
final class AutoLineup
{
    /**
     * Minutes before a window opens when it may already be filled.
     */
    public const LEAD_MINUTES = 30;

    /**
     * Every kind the lineup can post, and the order a brand without its own `lineup_priority` uses.
     */
    public const KINDS = ['catalog', 'comparison', 'deal'];

    /**
     * A window is not filled this close to its end: the post would go out with no time to spare.
     */
    private const CLOSE_MINUTES = 5;

    private const CANDIDATE_POOL = 100;

    /**
     * Link checks per window, so a stretch of dead pages cannot hold up one run.
     */
    private const LINK_CHECKS = 20;

    /**
     * Drafts that do not use a window or a channel's day: they were dropped or never went out.
     */
    private const NOT_COUNTED = [DraftStatus::Discarded, DraftStatus::Skipped, DraftStatus::Failed];

    public function __construct(
        private readonly CreateDraft $createDraft,
        private readonly PostingSchedule $schedule,
        private readonly LinkPreflight $links,
        private readonly DailyPostLimit $dailyLimit,
        private readonly RestrictedGoodsCheck $goods,
    ) {}

    /**
     * @return int How many drafts were created (0 or 1)
     */
    public function fill(Brand $brand, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        // A sync, an ingest and the hourly pass can all reach the same window; one of them decides it.
        try {
            return Cache::lock("auto-lineup:{$brand->id}", 120)->block(30, fn (): int => $this->fillOpenWindow($brand, $now));
        } catch (LockTimeoutException) {
            return 0;
        }
    }

    private function fillOpenWindow(Brand $brand, CarbonImmutable $now): int
    {
        $order = $this->kindOrder($brand);

        if ($order === []) {
            return 0;
        }

        $window = $this->openWindow($brand, $now);

        if ($window === null) {
            return 0;
        }

        [$from, $to, $index] = $window;

        $posted = $this->windowDrafts($brand, $from, $to);
        $count = (clone $posted)->count();

        if ($count >= $this->quota($brand, $index)) {
            return 0;
        }

        // The next post in the window goes one spacing after the last one, or at once if that moment has passed.
        $last = (clone $posted)->max('scheduled_at');
        $earliest = $last !== null
            ? CarbonImmutable::parse((string) $last, 'UTC')->addMinutes(PostingSchedule::SPACING_MINUTES)
            : $from;
        $slot = $now->greaterThan($earliest) ? $now : $earliest;

        if ($slot->greaterThanOrEqualTo($to->subMinutes(self::CLOSE_MINUTES))) {
            return 0;
        }

        if (! $this->dailyLimit->hasRoom($brand, $slot)) {
            return 0;
        }

        $rules = $this->rules($brand);

        if ($rules->isEmpty()) {
            return 0;
        }

        $accounts = SocialAccount::query()
            ->where('brand_id', $brand->id)
            ->active()
            ->whereIn('platform', $rules->pluck('platform')->map(fn ($platform): string => $platform->value)->all())
            ->get();

        $open = $this->channelsWithRoom($brand, $slot, $rules, $accounts);

        if ($open === []) {
            return 0;
        }

        $checks = 0;

        // Kind by kind, in the brand's order, so the best waiting item of the first kind is looked at before any of the
        // next. One pool across the kinds would be filled by whichever kind has the most items ranked highest, and a
        // leaflet behind a hundred deals would never be reached.
        foreach ($order as $kind) {
            $pool = ContentItem::query()
                ->whereIn('source_id', $rules->pluck('source_id')->unique()->all())
                ->where('kind', $kind)
                ->postableAt($slot)
                ->stillNews()
                ->notDrafted()
                ->orderByDesc('priority')
                ->orderByDesc('published_at')
                ->limit(self::CANDIDATE_POOL)
                ->get();

            foreach ($pool as $item) {
                if ($checks >= self::LINK_CHECKS) {
                    break 2;
                }

                if (! $item->isPostableAt($slot)) {
                    continue;
                }

                // The rules of the item's own source, for the channels that still have room today and take what the item
                // is (RestrictedGoodsCheck). An item no such channel takes would be skipped by the publish job, and held
                // the window until then, so it is passed over here, as ApplyAutoPublishRules does.
                $blocked = $this->goods->blockedOn($item);
                $itemRules = $rules
                    ->filter(fn (AutoPublishRule $rule): bool => $rule->source_id === $item->source_id
                        && in_array($rule->platform->value, $open, true)
                        && ! in_array($rule->platform->value, $blocked, true))
                    ->values();

                if ($itemRules->isEmpty()) {
                    continue;
                }

                $checks++;

                if (! $this->links->isAlive($item)) {
                    continue;
                }

                $platforms = $itemRules->map(fn (AutoPublishRule $rule): string => $rule->platform->value)->all();

                // A leaflet without a usable demo has no video. CreateDraft would save the draft before it found that
                // out, and the window would then hold a post with no media, so the leaflet is passed over here.
                if ($item->kind === ContentKind::Catalog) {
                    try {
                        CatalogDemo::fromItem($item);
                    } catch (InvalidArgumentException $e) {
                        Log::info('hub.autolineup.no_demo', ['item' => $item->id, 'reason' => $e->getMessage()]);

                        continue;
                    }
                }

                try {
                    $this->createDraft->execute(
                        item: $item,
                        accounts: $accounts->filter(fn (SocialAccount $account): bool => in_array($account->platform->value, $platforms, true))->values(),
                        actor: ActorType::System,
                        scheduledAt: $slot,
                        // One rule that wants a person's look holds the whole post, as in ApplyAutoPublishRules.
                        status: $itemRules->contains(fn (AutoPublishRule $rule): bool => $rule->requires_approval) ? DraftStatus::PendingApproval : DraftStatus::Approved,
                        channelOptions: $itemRules->mapWithKeys(fn (AutoPublishRule $rule): array => [$rule->platform->value => $rule->channelOptions()])->all(),
                    );
                } catch (Throwable $e) {
                    Log::warning('hub.autolineup.draft_failed', ['item' => $item->id, 'error' => $e->getMessage()]);

                    continue;
                }

                Log::info('hub.autolineup', ['brand' => $brand->slug, 'item' => $item->id, 'kind' => $item->kind->value, 'slot' => $slot->toIso8601String()]);

                return 1;
            }
        }

        return 0;
    }

    /**
     * The window that is open, or opens within LEAD_MINUTES: [from, to] in UTC, and its position in the day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}|null
     */
    private function openWindow(Brand $brand, CarbonImmutable $now): ?array
    {
        $local = $now->setTimezone($this->timezone($brand));
        $day = $local->startOfDay();

        foreach ($this->schedule->windows($brand) as $index => [$fromMinutes, $toMinutes]) {
            $from = $this->schedule->wallClock($day, $fromMinutes);
            $to = $this->schedule->wallClock($day, $toMinutes);

            if ($local->greaterThanOrEqualTo($from->subMinutes(self::LEAD_MINUTES)) && $local->lessThan($to)) {
                return [$from->utc(), $to->utc(), $index];
            }
        }

        return null;
    }

    /**
     * Posts that use a window: drafts planned or out in [from, to), whoever made them.
     *
     * @return Builder<PostDraft>
     */
    private function windowDrafts(Brand $brand, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return PostDraft::query()
            ->where('brand_id', $brand->id)
            ->whereNotIn('status', array_map(fn (DraftStatus $status): string => $status->value, self::NOT_COUNTED))
            ->where('scheduled_at', '>=', $from)
            ->where('scheduled_at', '<', $to);
    }

    /**
     * How many posts a window may take. The brand's daily limit is shared out over its windows, and the first
     * windows get the remainder: a limit of 5 over 3 windows gives 2, 2 and 1. A limit below the number of
     * windows gives each window one post, and the limit itself decides which windows get used.
     */
    private function quota(Brand $brand, int $index): int
    {
        $windows = count($this->schedule->windows($brand));
        $limit = $brand->daily_post_limit;

        if ($limit === null || $windows === 0) {
            return 1;
        }

        return max(1, intdiv($limit, $windows) + ($index < $limit % $windows ? 1 : 0));
    }

    /**
     * The enabled rules of this brand's enabled sources.
     *
     * @return Collection<int, AutoPublishRule>
     */
    private function rules(Brand $brand): Collection
    {
        return AutoPublishRule::query()
            ->where('enabled', true)
            ->whereHas('source', fn ($query) => $query->where('brand_id', $brand->id)->where('enabled', true))
            ->get();
    }

    /**
     * Platforms that have a connected account and are still under their daily cap on the slot's day.
     *
     * @param  Collection<int, AutoPublishRule>  $rules
     * @param  Collection<int, SocialAccount>  $accounts
     * @return list<string>
     */
    private function channelsWithRoom(Brand $brand, CarbonImmutable $slot, Collection $rules, Collection $accounts): array
    {
        $local = $slot->setTimezone($this->timezone($brand));
        $dayStart = $local->startOfDay()->utc();
        $dayEnd = $local->endOfDay()->utc();
        $open = [];

        $connected = $accounts->map(fn (SocialAccount $account): string => $account->platform->value)->unique()->all();

        foreach ($connected as $platform) {
            $cap = $rules
                ->filter(fn (AutoPublishRule $rule): bool => $rule->platform->value === $platform)
                ->max('daily_cap') ?? ApplyAutoPublishRules::DEFAULT_DAILY_CAP;

            $used = PostDraft::query()
                ->where('brand_id', $brand->id)
                ->whereNotIn('status', array_map(fn (DraftStatus $status): string => $status->value, self::NOT_COUNTED))
                ->where('scheduled_at', '>=', $dayStart)
                ->where('scheduled_at', '<=', $dayEnd)
                ->whereHas('variants', fn ($query) => $query->where('platform', $platform))
                ->count();

            if ($used < $cap) {
                $open[] = $platform;
            }
        }

        return $open;
    }

    /**
     * The kinds this brand's lineup posts, first kind first. Unknown kinds are dropped and a kind listed
     * twice counts once; a brand without its own order gets KINDS. An empty order posts nothing.
     *
     * @return list<string>
     */
    private function kindOrder(Brand $brand): array
    {
        if ($brand->lineup_priority === null) {
            return self::KINDS;
        }

        return array_values(array_unique(array_filter(
            $brand->lineup_priority,
            fn (mixed $kind): bool => is_string($kind) && in_array($kind, self::KINDS, true),
        )));
    }

    private function timezone(Brand $brand): string
    {
        return $brand->timezone ?: (string) config('hub.brand_default_timezone', 'Europe/Zagreb');
    }
}
