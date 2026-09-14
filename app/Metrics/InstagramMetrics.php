<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Metrics\Contracts\MetricsFetcher;
use App\Models\PostVariant;

/**
 * Instagram media insights (`/{media-id}/insights`); needs the `instagram_manage_insights` scope.
 * The same call answers for a photo, a carousel and a Reel.
 */
final class InstagramMetrics implements MetricsFetcher
{
    public function __construct(private readonly GraphInsights $insights) {}

    public function fetch(PostVariant $variant): ?MetricsSnapshot
    {
        $token = (string) $variant->account?->access_token;

        if ($token === '' || blank($variant->external_post_id)) {
            return null;
        }

        $raw = [];
        $values = $this->insights->read(
            "{$variant->external_post_id}/insights",
            (array) config('meta.insights.instagram', []),
            $token,
            $raw,
        );

        return MetricsSnapshot::fromColumns($values, $raw);
    }
}
