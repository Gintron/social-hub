<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Enums\ContentFormat;
use App\Metrics\Contracts\MetricsFetcher;
use App\Models\PostVariant;

/**
 * Facebook Page numbers. A Reel is a video object with its own insights edge; a photo, carousel or
 * link post is a feed post. Reactions, comments and shares come from the object itself — they are
 * readable with `pages_read_engagement`, while views and reach need `read_insights`.
 */
final class FacebookMetrics implements MetricsFetcher
{
    public function __construct(private readonly GraphInsights $insights) {}

    public function fetch(PostVariant $variant): ?MetricsSnapshot
    {
        $token = (string) $variant->account?->access_token;
        $id = (string) $variant->external_post_id;

        if ($token === '' || $id === '') {
            return null;
        }

        $raw = [];

        if ($variant->format() === ContentFormat::Video) {
            $values = $this->insights->read("{$id}/video_insights", (array) config('meta.insights.facebook_reel', []), $token, $raw);
            $object = $this->insights->fields($id, 'likes.summary(true).limit(0),comments.summary(true).limit(0)', $token, $raw);

            return MetricsSnapshot::fromColumns($values + array_filter([
                'likes' => self::count($object['likes']['summary']['total_count'] ?? null),
                'comments' => self::count($object['comments']['summary']['total_count'] ?? null),
            ], fn (?int $value): bool => $value !== null), $raw);
        }

        $values = $this->insights->read("{$id}/insights", (array) config('meta.insights.facebook_post', []), $token, $raw);
        $object = $this->insights->fields($id, 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares', $token, $raw);

        return MetricsSnapshot::fromColumns($values + array_filter([
            'likes' => self::count($object['reactions']['summary']['total_count'] ?? null),
            'comments' => self::count($object['comments']['summary']['total_count'] ?? null),
            'shares' => self::count($object['shares']['count'] ?? ($object !== [] ? 0 : null)),
        ], fn (?int $value): bool => $value !== null), $raw);
    }

    private static function count(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
