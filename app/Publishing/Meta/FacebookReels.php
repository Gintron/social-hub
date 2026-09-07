<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\TransientPublishException;
use App\Publishing\PublishResult;

/**
 * Facebook Page reels, which do not go through the ordinary feed endpoints.
 *
 * Three steps: ask for an upload session, hand the upload host a URL to fetch the file from, then
 * finish the session with the description. The middle step talks to rupload.facebook.com and
 * carries the token in a header, which is why it does not go through the normal Graph helper.
 *
 * @see https://developers.facebook.com/docs/video-api/guides/reels-publishing
 */
final class FacebookReels
{
    private const MIN_SECONDS = 3;

    /**
     * Facebook cuts Page reels off at 90 seconds.
     */
    private const MAX_SECONDS = 90;

    private const MAX_BYTES = 1024 * 1024 * 1024;

    public function __construct(private readonly GraphClient $graph) {}

    public function publish(PostVariant $variant, string $pageId, string $token, MediaAsset $asset, string $caption): PublishResult
    {
        $this->assertPublishable($asset);

        $graph = $this->graph->forVariant($variant);

        $session = $graph->post("{$pageId}/video_reels", ['upload_phase' => 'start'], $token, 'fb.reel.start');
        $videoId = $session['video_id'] ?? null;
        $uploadUrl = $session['upload_url'] ?? null;

        if (blank($videoId) || blank($uploadUrl)) {
            throw new TransientPublishException('Facebook nije vratio upload sesiju: '.json_encode($session), 'fb_reel_no_session');
        }

        // Meta fetches the file itself; `offset`/`file_size` are required even for a hosted file.
        $graph->upload((string) $uploadUrl, [
            'offset' => '0',
            'file_size' => (string) ($asset->bytes ?? 0),
            'file_url' => $asset->publicUrl(),
        ], $token, 'fb.reel.upload');

        $finish = $graph->post("{$pageId}/video_reels", [
            'upload_phase' => 'finish',
            'video_id' => (string) $videoId,
            'video_state' => 'PUBLISHED',
            'description' => $caption,
        ], $token, 'fb.reel.finish');

        if (($finish['success'] ?? true) === false) {
            throw new TransientPublishException('Facebook nije objavio reel: '.json_encode($finish), 'fb_reel_not_published');
        }

        return new PublishResult((string) $videoId, "https://www.facebook.com/reel/{$videoId}", $finish);
    }

    private function assertPublishable(MediaAsset $asset): void
    {
        if (! $asset->isVideo()) {
            throw new PermanentPublishException("Reel traži MP4; priloženo je {$asset->format}.", 'not_video');
        }

        $seconds = $asset->durationSeconds();

        if ($seconds !== null && ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS)) {
            throw new PermanentPublishException(
                sprintf('Video traje %.1f s; Facebook reel prima između %d s i %d s.', $seconds, self::MIN_SECONDS, self::MAX_SECONDS),
                'bad_video_duration',
            );
        }

        if ($asset->bytes !== null && $asset->bytes > self::MAX_BYTES) {
            throw new PermanentPublishException(sprintf('Video ima %.1f GB, dopušteno je 1 GB.', $asset->bytes / self::MAX_BYTES), 'video_too_large');
        }

        if ($asset->ratio() > 1.0) {
            throw new PermanentPublishException(
                sprintf('Video je %dx%d; reel traži uspravan format (9:16).', $asset->width, $asset->height),
                'reel_not_vertical',
            );
        }
    }
}
