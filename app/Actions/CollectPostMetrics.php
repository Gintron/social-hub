<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Metrics\Contracts\MetricsFetcher;
use App\Metrics\FacebookMetrics;
use App\Metrics\InstagramMetrics;
use App\Metrics\TikTokMetrics;
use App\Metrics\YouTubeMetrics;
use App\Models\PostMetric;
use App\Models\PostVariant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Asks the platform how a published post is doing and keeps the answer.
 *
 * Numbers move fast in a post's first days and barely afterwards, so a post is read every few
 * hours while it is new and once a day after that, until it is old enough to stop asking.
 */
final class CollectPostMetrics
{
    /**
     * Read every this many hours while the post is younger than FRESH_DAYS, daily after that.
     */
    public const FRESH_EVERY_HOURS = 6;

    public const FRESH_DAYS = 7;

    public const STOP_AFTER_DAYS = 30;

    public function __construct(
        private readonly InstagramMetrics $instagram,
        private readonly FacebookMetrics $facebook,
        private readonly TikTokMetrics $tiktok,
        private readonly YouTubeMetrics $youtube,
    ) {}

    public function execute(PostVariant $variant): ?PostMetric
    {
        $variant->loadMissing('account');

        if ($variant->platform === Platform::TikTok && ! $this->tiktok->supports($variant)) {
            return null;
        }

        $fetcher = $this->fetcherFor($variant->platform);

        if ($fetcher === null || blank($variant->external_post_id)) {
            return null;
        }

        $snapshot = $fetcher->fetch($variant);

        // An empty reading would drag every average towards zero; the next pass asks again.
        if ($snapshot === null || $snapshot->isEmpty()) {
            return null;
        }

        return PostMetric::query()->create([
            'post_variant_id' => $variant->id,
            'captured_at' => CarbonImmutable::now(),
            ...$snapshot->columns(),
            'raw' => $snapshot->raw,
        ]);
    }

    /**
     * Published posts whose numbers are due for another read.
     *
     * @return Collection<int, PostVariant>
     */
    public function due(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        return PostVariant::query()
            ->with(['latestMetric', 'account'])
            ->whereIn('status', [VariantStatus::Published->value, VariantStatus::ManualDone->value])
            ->whereIn('platform', [Platform::FacebookPage->value, Platform::InstagramBusiness->value, Platform::TikTok->value, Platform::YouTube->value])
            ->whereNotNull('external_post_id')
            ->where(fn ($query) => $query
                ->where('published_at', '>=', $now->subDays(self::STOP_AFTER_DAYS))
                ->orWhere('manual_posted_at', '>=', $now->subDays(self::STOP_AFTER_DAYS)))
            ->get()
            ->filter(function (PostVariant $variant) use ($now): bool {
                if ($variant->platform === Platform::TikTok && ! $this->tiktok->supports($variant)) {
                    return false;
                }

                $last = $variant->latestMetric?->captured_at;

                if ($last === null) {
                    return true;
                }

                $postedAt = $variant->published_at ?? $variant->manual_posted_at ?? $now;
                $fresh = CarbonImmutable::parse($postedAt)->greaterThan($now->subDays(self::FRESH_DAYS));

                return $last->lessThanOrEqualTo($fresh ? $now->subHours(self::FRESH_EVERY_HOURS) : $now->subDay());
            })
            ->values();
    }

    private function fetcherFor(Platform $platform): ?MetricsFetcher
    {
        return match ($platform) {
            Platform::InstagramBusiness => $this->instagram,
            Platform::FacebookPage => $this->facebook,
            Platform::TikTok => $this->tiktok,
            Platform::YouTube => $this->youtube,
            // A group post is pasted by a person; there is no API to ask.
            Platform::FacebookGroup => null,
        };
    }
}
