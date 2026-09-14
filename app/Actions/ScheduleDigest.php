<?php

declare(strict_types=1);

namespace App\Actions;

use App\Drafting\DigestBuilder;
use App\Enums\ActorType;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The brand's recurring roundup ("5 oglasa ovog tjedna"), built and scheduled on the days and at
 * the time set on the brand (`brands.digest`).
 *
 * It is automation, so it follows the auto-publish switches: it goes only to channels whose rule
 * is enabled on one of the brand's sources, with that rule's settings — never to a manual channel,
 * which already gets every item on its own.
 */
final class ScheduleDigest
{
    public const DEFAULT_TIME = '19:00';

    public const DEFAULT_COUNT = 5;

    public function __construct(
        private readonly DigestBuilder $builder,
        private readonly PostingSchedule $schedule,
    ) {}

    /**
     * @return PostDraft|null The scheduled digest, or null when none is due now, one was already
     *                        built today, no channel takes it, or there are too few items.
     */
    public function execute(Brand $brand, ?CarbonImmutable $now = null): ?PostDraft
    {
        $config = (array) ($brand->digest ?? []);

        if (! ($config['enabled'] ?? false)) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $local = $now->setTimezone($brand->timezone ?: (string) config('hub.brand_default_timezone', 'Europe/Zagreb'));
        $days = array_map('intval', (array) ($config['days'] ?? []));

        if (! in_array($local->dayOfWeekIso, $days, true)) {
            return null;
        }

        $at = $this->at($local, (string) ($config['time'] ?? self::DEFAULT_TIME));

        // Built an hour ahead, so the slides have rendered by the time the post is due.
        if ($local->lessThan($at->subHour()) || $this->builtToday($brand, $local)) {
            return null;
        }

        $rules = AutoPublishRule::query()
            ->where('enabled', true)
            ->whereHas('source', fn ($query) => $query->where('brand_id', $brand->id))
            ->get()
            ->reject(fn (AutoPublishRule $rule): bool => $rule->platform->isManual());

        $accounts = SocialAccount::query()
            ->where('brand_id', $brand->id)
            ->active()
            ->whereIn('platform', $rules->map(fn (AutoPublishRule $rule): string => $rule->platform->value)->unique()->all())
            ->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        $earliest = $at->greaterThan($now) ? $at->utc() : $now->utc();
        $scheduledAt = $this->schedule->nextSlot($brand, $this->schedule->queueAfter($brand, $earliest));

        try {
            return $this->builder->build(
                brand: $brand,
                kind: ContentKind::tryFrom((string) ($config['kind'] ?? '')) ?? ContentKind::Job,
                accounts: $accounts,
                count: (int) ($config['count'] ?? self::DEFAULT_COUNT),
                actor: ActorType::System,
                scheduledAt: $scheduledAt,
                status: DraftStatus::Approved,
                includePosted: true,
                channelSettings: $rules->mapWithKeys(fn (AutoPublishRule $rule): array => [$rule->platform->value => (array) $rule->settings])->all(),
            );
        } catch (InvalidArgumentException $e) {
            Log::info('hub.digest.skipped', ['brand' => $brand->slug, 'reason' => $e->getMessage()]);

            return null;
        }
    }

    private function at(CarbonImmutable $local, string $time): CarbonImmutable
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            $matches = [null, '19', '00'];
        }

        return $local->setTime(min(23, (int) $matches[1]), min(59, (int) $matches[2]));
    }

    private function builtToday(Brand $brand, CarbonImmutable $local): bool
    {
        return PostDraft::query()
            ->where('brand_id', $brand->id)
            ->where('kind', PostDraft::KIND_DIGEST)
            ->where('created_by_type', ActorType::System->value)
            ->where('created_at', '>=', $local->startOfDay()->utc())
            ->exists();
    }
}
