<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Metrics\Contracts\MetricsFetcher;
use App\Models\PostVariant;
use App\Publishing\YouTube\YouTubeClient;
use App\Publishing\YouTube\YouTubeTokens;

/**
 * YouTube counts from `videos.list` with `part=statistics`: one unit a call, under the readonly scope.
 *
 * The id is the one the upload returned, so no lookup comes first. A count Google leaves out (comments
 * switched off, likes hidden by the channel) stays null rather than becoming zero.
 */
final class YouTubeMetrics implements MetricsFetcher
{
    public function __construct(
        private readonly YouTubeClient $client,
        private readonly YouTubeTokens $tokens,
    ) {}

    public function fetch(PostVariant $variant): ?MetricsSnapshot
    {
        $account = $variant->account;

        if ($account === null || blank($variant->external_post_id)) {
            return null;
        }

        $token = $this->tokens->accessTokenFor($account);

        $data = $this->client->forVariant($variant)->get(
            'videos',
            ['part' => 'statistics', 'id' => (string) $variant->external_post_id],
            $token,
            'metrics.youtube',
        );

        // A video that was deleted on YouTube comes back with no items; there is nothing to record.
        $video = collect((array) ($data['items'] ?? []))->first();

        if (! is_array($video)) {
            return null;
        }

        $statistics = (array) ($video['statistics'] ?? []);

        return new MetricsSnapshot(
            views: self::count($statistics['viewCount'] ?? null),
            likes: self::count($statistics['likeCount'] ?? null),
            comments: self::count($statistics['commentCount'] ?? null),
            raw: ['video' => $video],
        );
    }

    private static function count(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
