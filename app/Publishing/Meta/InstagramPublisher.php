<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Publishing\Contracts\Publisher;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Exceptions\RateLimitedException;
use App\Publishing\Exceptions\TransientPublishException;
use App\Publishing\PublishResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Instagram publishing through the Graph API.
 *
 * Two steps, always: build a media container, then publish it. A carousel is one container per
 * slide plus a parent container that lists them. Containers are processed asynchronously, so each
 * one is polled until it reports FINISHED before it can be published.
 *
 * Variant settings: `first_comment` (string) posts a follow-up comment, typically the hashtag block.
 */
final class InstagramPublisher implements Publisher
{
    /**
     * Image containers finish in a second or two; the ceiling is for when Meta is having a bad day.
     */
    private const POLL_INTERVAL_SECONDS = 3;

    private const POLL_TIMEOUT_SECONDS = 90;

    /**
     * Video containers are transcoded, not just fetched, so they need a far longer leash.
     */
    private const VIDEO_POLL_TIMEOUT_SECONDS = 600;

    public function __construct(
        private readonly GraphClient $graph,
        private readonly InstagramPreflight $preflight,
    ) {}

    public function supports(Platform $platform): bool
    {
        return $platform === Platform::InstagramBusiness;
    }

    public function publish(PostVariant $variant): PublishResult
    {
        $account = $variant->account;

        if ($account === null || blank($account->access_token)) {
            throw new PermanentPublishException('Varijanta nema povezan Instagram račun (ili nedostaje token).', 'no_account');
        }

        $graph = $this->graph->forVariant($variant);
        $token = (string) $account->access_token;
        $igUserId = $account->external_id;

        /** @var Collection<int, MediaAsset> $media */
        $media = $variant->media()->get();

        $caption = $this->preflight->caption($variant);
        $isReel = $variant->format() === ContentFormat::Video;

        if ($isReel) {
            $this->preflight->video($media);
        } else {
            $this->preflight->media($media);
        }

        $this->assertQuota($graph, $igUserId, $token, $variant);

        $creationId = match (true) {
            $isReel => $this->reelContainer($graph, $igUserId, $token, $media->first(), $caption, $variant),
            $media->count() === 1 => $this->singleContainer($graph, $igUserId, $token, $media->first(), $caption),
            default => $this->carouselContainer($graph, $igUserId, $token, $media, $caption),
        };

        $this->awaitContainer($graph, $creationId, $token, $isReel ? self::VIDEO_POLL_TIMEOUT_SECONDS : self::POLL_TIMEOUT_SECONDS);

        $published = $graph->post("{$igUserId}/media_publish", ['creation_id' => $creationId], $token, 'ig.publish');
        $mediaId = $published['id'] ?? null;

        if (blank($mediaId)) {
            throw new TransientPublishException('Instagram nije vratio id objave: '.json_encode($published), 'ig_no_media_id');
        }

        $permalink = $this->permalink($graph, (string) $mediaId, $token);
        $this->firstComment($graph, $variant, (string) $mediaId, $token);

        return new PublishResult((string) $mediaId, $permalink, $published);
    }

    /**
     * A reel: one video container, optionally also shown in the main feed.
     *
     * Variant settings: `share_to_feed` (default true), `cover_url`.
     */
    private function reelContainer(GraphClient $graph, string $igUserId, string $token, MediaAsset $asset, string $caption, PostVariant $variant): string
    {
        $payload = [
            'media_type' => 'REELS',
            'video_url' => $asset->publicUrl(),
            'caption' => $caption,
            'share_to_feed' => $variant->setting('share_to_feed', true) ? 'true' : 'false',
        ];

        $cover = $asset->poster?->publicUrl() ?? $variant->setting('cover_url');

        if (filled($cover)) {
            $payload['cover_url'] = (string) $cover;
        }

        return $this->containerId($graph->post("{$igUserId}/media", $payload, $token, 'ig.reel'));
    }

    private function singleContainer(GraphClient $graph, string $igUserId, string $token, MediaAsset $asset, string $caption): string
    {
        $response = $graph->post("{$igUserId}/media", [
            'image_url' => $asset->publicUrl(),
            'caption' => $caption,
        ], $token, 'ig.container');

        return $this->containerId($response);
    }

    /**
     * @param  Collection<int, MediaAsset>  $media
     */
    private function carouselContainer(GraphClient $graph, string $igUserId, string $token, Collection $media, string $caption): string
    {
        $children = [];

        foreach ($media as $asset) {
            $response = $graph->post("{$igUserId}/media", [
                'image_url' => $asset->publicUrl(),
                'is_carousel_item' => 'true',
            ], $token, 'ig.carousel_child');

            $children[] = $this->containerId($response);
        }

        // Children must be ready before the parent references them, otherwise the parent
        // container fails with an unhelpful error.
        foreach ($children as $childId) {
            $this->awaitContainer($graph, $childId, $token);
        }

        $response = $graph->post("{$igUserId}/media", [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => $caption,
        ], $token, 'ig.carousel');

        return $this->containerId($response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function containerId(array $response): string
    {
        $id = $response['id'] ?? null;

        if (blank($id)) {
            throw new TransientPublishException('Instagram nije vratio id spremnika: '.json_encode($response), 'ig_no_container_id');
        }

        return (string) $id;
    }

    /**
     * Poll a container until Instagram finishes processing it.
     */
    private function awaitContainer(GraphClient $graph, string $containerId, string $token, int $timeoutSeconds = self::POLL_TIMEOUT_SECONDS): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $status = $graph->get($containerId, ['fields' => 'status_code,status'], $token, 'ig.container_status');
            $code = (string) ($status['status_code'] ?? '');

            if ($code === 'FINISHED' || $code === 'PUBLISHED') {
                return;
            }

            if ($code === 'ERROR') {
                throw new PermanentPublishException(
                    'Instagram je odbio spremnik: '.($status['status'] ?? 'bez detalja'),
                    'ig_container_error',
                );
            }

            if ($code === 'EXPIRED') {
                throw new TransientPublishException('Spremnik je istekao prije objave (Instagram ih briše nakon 24 h).', 'ig_container_expired');
            }

            if (microtime(true) >= $deadline) {
                throw new TransientPublishException(
                    sprintf('Instagram obrađuje spremnik dulje od %d s (zadnji status: %s).', $timeoutSeconds, $code ?: 'nepoznat'),
                    'ig_container_timeout',
                );
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
        }
    }

    private function permalink(GraphClient $graph, string $mediaId, string $token): ?string
    {
        try {
            $media = $graph->get($mediaId, ['fields' => 'permalink'], $token, 'ig.permalink');

            return $media['permalink'] ?? null;
        } catch (PublishException $e) {
            // The post is live; a missing permalink is cosmetic.
            Log::warning('hub.ig.permalink_failed', ['media_id' => $mediaId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Optional follow-up comment. The post is already published at this point, so a failure here
     * must never fail the variant.
     */
    private function firstComment(GraphClient $graph, PostVariant $variant, string $mediaId, string $token): void
    {
        $comment = $variant->setting('first_comment');

        if (blank($comment)) {
            return;
        }

        try {
            $graph->post("{$mediaId}/comments", ['message' => (string) $comment], $token, 'ig.first_comment');
        } catch (PublishException $e) {
            Log::warning('hub.ig.first_comment_failed', ['variant' => $variant->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Instagram allows 100 API-published posts per rolling 24 h per account. Ask Meta for the
     * authoritative number; fall back to counting what the hub itself published if that call fails.
     */
    private function assertQuota(GraphClient $graph, string $igUserId, string $token, PostVariant $variant): void
    {
        $limit = (int) config('hub.limits.ig_daily_posts', 100);

        try {
            $response = $graph->get("{$igUserId}/content_publishing_limit", ['fields' => 'quota_usage,config'], $token, 'ig.quota');
            $row = $response['data'][0] ?? [];
            $used = (int) ($row['quota_usage'] ?? 0);
            $total = (int) ($row['config']['quota_total'] ?? $limit);
            $window = (int) ($row['config']['quota_duration'] ?? 86400);

            if ($used >= $total) {
                throw new RateLimitedException("Instagram dnevna kvota je potrošena ({$used}/{$total}).", max(600, (int) ($window / 12)));
            }

            return;
        } catch (RateLimitedException $e) {
            throw $e;
        } catch (PublishException $e) {
            Log::warning('hub.ig.quota_check_failed', ['account' => $igUserId, 'error' => $e->getMessage()]);
        }

        $published = PostVariant::query()
            ->where('social_account_id', $variant->social_account_id)
            ->where('status', VariantStatus::Published->value)
            ->where('published_at', '>=', now()->subDay())
            ->count();

        if ($published >= $limit) {
            throw new RateLimitedException("Hub je u zadnja 24 h objavio {$published} objava na ovaj račun (limit {$limit}).", 3600);
        }
    }
}
