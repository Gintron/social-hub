<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Metrics\Contracts\MetricsFetcher;
use App\Models\PostVariant;
use App\Publishing\TikTok\TikTokClient;

/**
 * TikTok counts from `video/query` (scope `video.list`).
 *
 * The hub only knows the publish_id it got when posting; the public video id appears once TikTok
 * has published the post (or the creator has, for an inbox upload). It is looked up through
 * `publish/status/fetch` and kept on the variant, so the lookup happens once.
 */
final class TikTokMetrics implements MetricsFetcher
{
    public const VIDEO_ID_SETTING = 'tiktok_video_id';

    public function __construct(private readonly TikTokClient $client) {}

    public function fetch(PostVariant $variant): ?MetricsSnapshot
    {
        $token = (string) $variant->account?->access_token;

        if ($token === '' || blank($variant->external_post_id)) {
            return null;
        }

        $videoId = $this->videoId($variant, $token);

        if ($videoId === null) {
            return null;
        }

        $data = $this->client->post(
            'video/query/?fields=id,view_count,like_count,comment_count,share_count',
            ['filters' => ['video_ids' => [$videoId]]],
            $token,
            'metrics.tiktok',
        );

        $video = collect((array) ($data['videos'] ?? []))->first(fn (array $row): bool => (string) ($row['id'] ?? '') === $videoId);

        if ($video === null) {
            return null;
        }

        return new MetricsSnapshot(
            views: self::count($video['view_count'] ?? null),
            likes: self::count($video['like_count'] ?? null),
            comments: self::count($video['comment_count'] ?? null),
            shares: self::count($video['share_count'] ?? null),
            raw: ['video' => $video],
        );
    }

    private static function count(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function videoId(PostVariant $variant, string $token): ?string
    {
        $known = $variant->setting(self::VIDEO_ID_SETTING);

        if (filled($known)) {
            return (string) $known;
        }

        $status = $this->client->post('post/publish/status/fetch/', ['publish_id' => (string) $variant->external_post_id], $token, 'metrics.tiktok_status');
        // Sic: TikTok's field name.
        $ids = (array) ($status['publicaly_available_post_id'] ?? []);
        $id = $ids[0] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            return null;
        }

        $variant->putSettings([self::VIDEO_ID_SETTING => (string) $id]);

        return (string) $id;
    }
}
