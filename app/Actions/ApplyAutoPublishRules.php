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
use App\Publishing\RestrictedGoodsCheck;
use App\Support\DailyPostLimit;
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

    /**
     * How many days on, at most, an item goes when the days before it are full on every channel.
     */
    private const SLOT_DAYS_AHEAD = 14;

    public function __construct(
        private readonly CreateDraft $createDraft,
        private readonly PostingSchedule $schedule,
        private readonly LinkPreflight $links,
        private readonly DailyPostLimit $dailyLimit,
        private readonly AutoLineup $lineup,
        private readonly RestrictedGoodsCheck $goods,
    ) {}

    /**
     * @return int How many drafts were created
     */
    public function execute(Source $source): int
    {
        // A brand on the lineup takes one item per posting window, whichever source it came from.
        if ($source->brand?->auto_lineup) {
            return $this->lineup->fill($source->brand);
        }

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

        $delay = (int) $rules->max('delay_minutes');
        $after = $this->schedule->queueAfter($source->brand, CarbonImmutable::now()->addMinutes($delay));
        $slots = $this->schedule->slots($source->brand, $after, $most);

        // Postable at the first slot narrows the pool in the database; each item is then held to
        // the slot it will actually get, so an offer that ends soon never lands on a late one.
        $items = collect();

        foreach ($this->candidates($source, $slots[0], $most) as $item) {
            if ($items->count() >= $most) {
                break;
            }

            // An item none of the connected channels takes does not hold a slot: the next item gets it (RestrictedGoodsCheck).
            if (array_diff($connected, $this->goods->blockedOn($item)) === []) {
                continue;
            }

            if ($item->isPostableAt($slots[$items->count()]) && $this->links->isAlive($item)) {
                $items->push($item);
            }
        }

        if ($items->isEmpty()) {
            return 0;
        }

        $options = $rules->mapWithKeys(fn (AutoPublishRule $rule): array => [$rule->platform->value => $rule->channelOptions()])->all();

        $ruleFor = $rules->keyBy(fn (AutoPublishRule $rule): string => $rule->platform->value);

        // A draft is made for the channels that are connected when the pass runs, and its item is then used up for
        // good: a channel with a rule but no active account (a lapsed token) misses every post of this pass.
        $missing = array_values(array_diff($ruleFor->keys()->all(), $connected));

        if ($missing !== []) {
            Log::warning('hub.autopublish.channel_missing', ['source' => $source->name, 'platforms' => $missing]);
        }

        // Posts the source already has on each channel and day, read once and kept up to date as drafts are made.
        $onDay = [];
        $floor = null;
        $created = 0;

        foreach ($items as $item) {
            // Goods a channel does not take (RestrictedGoodsCheck) go only to the channels that do. An item none
            // of the open channels takes is passed over, and its slot goes to the next item.
            $blocked = $this->goods->blockedOn($item);
            $eligible = $accounts->reject(fn (SocialAccount $account): bool => in_array($account->platform->value, $blocked, true));

            if ($eligible->isEmpty()) {
                continue;
            }

            $slot = $slots[$created];

            if ($floor !== null && $slot->lessThan($floor)) {
                $slot = $this->schedule->nextSlot($source->brand, $floor);
            }

            $targets = $this->targetsAt($source, $eligible, $ruleFor, $remaining, $slot, $onDay);

            // A day on which every channel has had its share does not hold the item: it goes out on the next day with room.
            for ($days = 0; $targets->isEmpty() && $days < self::SLOT_DAYS_AHEAD; $days++) {
                $slot = $this->schedule->nextSlot($source->brand, $source->brand->dayBoundsUtc($slot)[1]->addSecond());
                $targets = $this->targetsAt($source, $eligible, $ruleFor, $remaining, $slot, $onDay);
            }

            if ($targets->isEmpty() || ! $item->isPostableAt($slot)) {
                continue;
            }

            $targeted = $targets->map(fn (SocialAccount $account): string => $account->platform->value)->unique()->values()->all();

            // The brand's own limit for the day this post would go out, on top of each rule's cap.
            if (! $this->dailyLimit->hasRoom($source->brand, $slot)) {
                Log::info('hub.autopublish.daily_post_limit', ['source' => $source->name, 'slot' => $slot->toIso8601String()]);

                break;
            }

            try {
                $this->createDraft->execute(
                    item: $item,
                    accounts: $targets,
                    actor: ActorType::System,
                    scheduledAt: $slot,
                    // One rule asking for a person's look is enough to hold the whole draft: it is one post, and
                    // approving it approves every channel it goes to.
                    status: $this->needsApproval($rules, $targeted) ? DraftStatus::PendingApproval : DraftStatus::Approved,
                    channelOptions: $options,
                );

                foreach ($targeted as $platform) {
                    $remaining[$platform]--;
                    $onDay[$this->dayKey($source, $platform, $slot)]++;
                }

                $floor = $slot->addMinutes(PostingSchedule::SPACING_MINUTES);
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
     * The accounts that take the post going out at `$slot`.
     *
     * A channel takes it up to its cap for the brand's day of the post, counted by the day the post goes out. The
     * pacing (`$remaining`: how many posts the passes of today may still add) alone must not decide who gets an
     * item: a channel connected later has used nothing today while the others have, so it was the only one open
     * and took the day's item alone, and Facebook and Instagram lost that day for good (an item already in a draft
     * is never picked again). So a channel with nothing on that day takes the item whatever its pacing says, and
     * one with posts on it takes it only while the pacing and its cap allow.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  Collection<string, AutoPublishRule>  $ruleFor  platform value => rule
     * @param  array<string, int>  $remaining  platform value => posts left today
     * @param  array<string, int>  $onDay  counts by `dayKey`, filled as they are read
     * @return Collection<int, SocialAccount>
     */
    private function targetsAt(Source $source, Collection $accounts, Collection $ruleFor, array $remaining, CarbonImmutable $slot, array &$onDay): Collection
    {
        return $accounts->filter(function (SocialAccount $account) use ($source, $ruleFor, $remaining, $slot, &$onDay): bool {
            $platform = $account->platform->value;
            $key = $this->dayKey($source, $platform, $slot);
            $onDay[$key] ??= $this->postsOnDay($source, $platform, $slot);

            return $onDay[$key] < ($ruleFor[$platform]->daily_cap ?? self::DEFAULT_DAILY_CAP)
                && (($remaining[$platform] ?? 0) > 0 || $onDay[$key] === 0);
        })->values();
    }

    private function dayKey(Source $source, string $platform, CarbonImmutable $slot): string
    {
        return $platform.'|'.$source->brand->dayBoundsUtc($slot)[0]->toDateTimeString();
    }

    /**
     * Automatic single posts of the source that go out on the brand's local day of `$slot`, on a channel.
     */
    private function postsOnDay(Source $source, string $platform, CarbonImmutable $slot): int
    {
        return PostDraft::query()
            ->where('created_by_type', ActorType::System->value)
            ->where('kind', PostDraft::KIND_SINGLE)
            ->whereBetween('scheduled_at', $source->brand->dayBoundsUtc($slot))
            ->whereHas('contentItems', fn ($query) => $query->where('source_id', $source->id))
            ->whereHas('variants', fn ($query) => $query->where('platform', $platform))
            ->count();
    }

    /**
     * @param  Collection<int, AutoPublishRule>  $rules
     * @param  list<string>  $platforms  The channels this draft goes to.
     */
    private function needsApproval(Collection $rules, array $platforms): bool
    {
        return $rules->contains(fn (AutoPublishRule $rule): bool => $rule->requires_approval && in_array($rule->platform->value, $platforms, true));
    }

    /**
     * The best items of the source that are still worth posting when the first slot comes, highest
     * priority first. More are read than are needed: some will be passed over.
     *
     * @return Collection<int, ContentItem>
     */
    private function candidates(Source $source, CarbonImmutable $firstSlot, int $needed): Collection
    {
        return ContentItem::query()
            ->where('source_id', $source->id)
            ->postableAt($firstSlot)
            ->stillNews()
            ->notDrafted()
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->limit(min(self::CANDIDATE_POOL, $needed * self::CANDIDATES_PER_POST))
            ->get();
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
