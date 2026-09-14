<?php

declare(strict_types=1);

namespace App\Actions;

use App\Drafting\DigestBuilder;
use App\Drafting\DigestSeries;
use App\Enums\ActorType;
use App\Enums\DraftStatus;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The brand's recurring roundups ("Top 7 akcija u Kauflandu"), each built and scheduled on the days
 * and at the time set for its series (`brands.digests`).
 *
 * It is automation, so it follows the auto-publish switches: it goes only to channels whose rule
 * is enabled on one of the brand's sources, with that rule's settings — never to a manual channel,
 * which already gets every item on its own.
 */
final class ScheduleDigest
{
    public function __construct(
        private readonly DigestBuilder $builder,
        private readonly PostingSchedule $schedule,
    ) {}

    /**
     * @return list<PostDraft> The digests scheduled now: one per series whose day and hour have come
     *                         and that was not built yet today, if a channel takes it and it has
     *                         enough items.
     */
    public function execute(Brand $brand, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $local = $now->setTimezone($brand->timezone ?: (string) config('hub.brand_default_timezone', 'Europe/Zagreb'));

        // Built an hour ahead, so the slides have rendered by the time the post is due.
        $due = array_values(array_filter(
            $brand->digestSeries(),
            fn (DigestSeries $series): bool => $series->enabled
                && $series->isDueOn($local)
                && ! $local->lessThan($series->at($local)->subHour())
                && ! $this->builtToday($brand, $series, $local),
        ));

        if ($due === []) {
            return [];
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
            return [];
        }

        $drafts = [];

        foreach ($due as $series) {
            $draft = $this->build($brand, $series, $rules, $accounts, $now, $local);

            if ($draft !== null) {
                $drafts[] = $draft;
            }
        }

        return $drafts;
    }

    /**
     * @param  Collection<int, AutoPublishRule>  $rules
     * @param  Collection<int, SocialAccount>  $accounts
     */
    private function build(Brand $brand, DigestSeries $series, Collection $rules, Collection $accounts, CarbonImmutable $now, CarbonImmutable $local): ?PostDraft
    {
        $at = $series->at($local);
        $earliest = $at->greaterThan($now) ? $at->utc() : $now->utc();
        // Two series on the same evening queue one behind the other, like any automated post.
        $scheduledAt = $this->schedule->nextSlot($brand, $this->schedule->queueAfter($brand, $earliest));

        try {
            return $this->builder->build(
                brand: $brand,
                kind: $series->kind,
                accounts: $accounts,
                count: $series->count,
                headline: $series->headline,
                actor: ActorType::System,
                scheduledAt: $scheduledAt,
                status: DraftStatus::Approved,
                includePosted: true,
                channelSettings: $rules->mapWithKeys(fn (AutoPublishRule $rule): array => [$rule->platform->value => (array) $rule->settings])->all(),
                tag: $series->tag,
                formats: $accounts->mapWithKeys(fn (SocialAccount $account): array => [$account->platform->value => $series->formatFor($account->platform)])->all(),
                series: $series->key,
            );
        } catch (InvalidArgumentException $e) {
            Log::info('hub.digest.skipped', ['brand' => $brand->slug, 'series' => $series->name, 'reason' => $e->getMessage()]);

            return null;
        }
    }

    private function builtToday(Brand $brand, DigestSeries $series, CarbonImmutable $local): bool
    {
        return PostDraft::query()
            ->where('brand_id', $brand->id)
            ->where('kind', PostDraft::KIND_DIGEST)
            ->where('digest_series', $series->key)
            ->where('created_at', '>=', $local->startOfDay()->utc())
            ->exists();
    }
}
