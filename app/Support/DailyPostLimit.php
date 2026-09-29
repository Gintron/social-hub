<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DraftStatus;
use App\Models\Brand;
use App\Models\PostDraft;
use Carbon\CarbonImmutable;

/**
 * How many posts automation may still add to a brand's day (`brands.daily_post_limit`).
 *
 * The per-channel `daily_cap` of a rule cannot promise this: a brand has several rules, and
 * digests answer to none of them. The limit counts every post that is due out on the brand's
 * local day — whoever made it — but only stops automation; a person may always add one more.
 */
final class DailyPostLimit
{
    /**
     * Drafts that go out, or went out, count. Waiting for approval, failed, skipped and discarded
     * ones do not use up the day.
     */
    private const COUNTED = [
        DraftStatus::Approved,
        DraftStatus::Scheduled,
        DraftStatus::Publishing,
        DraftStatus::Published,
        DraftStatus::PartiallyPublished,
    ];

    /**
     * @return int|null Posts automation may still add on the local day of `$at`; null = no limit.
     */
    public function remaining(Brand $brand, CarbonImmutable $at): ?int
    {
        if ($brand->daily_post_limit === null) {
            return null;
        }

        $day = $at->setTimezone($brand->timezone ?: (string) config('hub.brand_default_timezone', 'Europe/Zagreb'));
        $from = $day->startOfDay()->utc();
        $to = $day->endOfDay()->utc();

        // A draft published on the spot has no time set: it counts on the day it was made.
        $used = PostDraft::query()
            ->where('brand_id', $brand->id)
            ->whereIn('status', array_map(fn (DraftStatus $status): string => $status->value, self::COUNTED))
            ->where(fn ($query) => $query
                ->whereBetween('scheduled_at', [$from, $to])
                ->orWhere(fn ($unscheduled) => $unscheduled->whereNull('scheduled_at')->whereBetween('created_at', [$from, $to])))
            ->count();

        return max(0, $brand->daily_post_limit - $used);
    }

    public function hasRoom(Brand $brand, CarbonImmutable $at): bool
    {
        return ($this->remaining($brand, $at) ?? 1) > 0;
    }
}
