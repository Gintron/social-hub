<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use Carbon\CarbonImmutable;

/**
 * When may a post go out.
 *
 * A brand publishes inside posting windows (local time), so automation never drops a post at
 * 03:40 because that is when a scraper happened to run. Windows are stored per brand as
 * [{from: "08:00", to: "20:00"}]; a brand without windows may post at any time.
 */
final class PostingSchedule
{
    /**
     * Minutes between two automated posts of one brand.
     */
    public const SPACING_MINUTES = 45;

    /**
     * How far ahead to look before giving up and posting at the requested time anyway.
     */
    private const MAX_DAYS_AHEAD = 14;

    /**
     * First moment at or after `$after` that falls inside one of the brand's posting windows.
     */
    public function nextSlot(Brand $brand, CarbonImmutable $after): CarbonImmutable
    {
        $windows = $this->windows($brand);

        if ($windows === []) {
            return $after;
        }

        $timezone = $brand->timezone ?: (string) config('hub.brand_default_timezone', 'Europe/Zagreb');
        $local = $after->setTimezone($timezone);

        for ($day = 0; $day <= self::MAX_DAYS_AHEAD; $day++) {
            $date = $local->addDays($day)->startOfDay();

            foreach ($windows as [$fromMinutes, $toMinutes]) {
                $start = $date->addMinutes($fromMinutes);
                $end = $date->addMinutes($toMinutes);

                if ($local->lessThanOrEqualTo($start)) {
                    return $start->setTimezone($after->timezone);
                }

                if ($local->lessThanOrEqualTo($end)) {
                    return $local->setTimezone($after->timezone);
                }
            }
        }

        return $after;
    }

    /**
     * Slots for a batch, spaced apart so several new items do not land as one wall of posts.
     *
     * @return list<CarbonImmutable>
     */
    public function slots(Brand $brand, CarbonImmutable $after, int $count, int $spacingMinutes = self::SPACING_MINUTES): array
    {
        $slots = [];
        $cursor = $after;

        for ($i = 0; $i < $count; $i++) {
            $slot = $this->nextSlot($brand, $cursor);
            $slots[] = $slot;
            $cursor = $slot->addMinutes(max(1, $spacingMinutes));
        }

        return $slots;
    }

    /**
     * Windows as [startMinute, endMinute] pairs, sorted, ignoring malformed entries.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function windows(Brand $brand): array
    {
        $windows = [];

        foreach ($brand->posting_windows ?? [] as $window) {
            $from = $this->minutes($window['from'] ?? null);
            $to = $this->minutes($window['to'] ?? null);

            if ($from === null || $to === null || $to <= $from) {
                continue;
            }

            $windows[] = [$from, $to];
        }

        usort($windows, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $windows;
    }

    private function minutes(mixed $time): ?int
    {
        if (! is_string($time) || preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $mins = (int) $matches[2];

        if ($hours > 23 || $mins > 59) {
            return null;
        }

        return $hours * 60 + $mins;
    }
}
