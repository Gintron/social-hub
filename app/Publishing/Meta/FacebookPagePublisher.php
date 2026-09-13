<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Publishing\Contracts\Publisher;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\PublishResult;

/**
 * Facebook Page posts via Graph API.
 *
 * - link                  → POST /{page}/feed {message, link}
 * - image                 → POST /{page}/photos {url, caption}
 * - carousel              → POST /{page}/photos {url, published=false} × n, then POST /{page}/feed {message, attached_media}
 * - video                 → a Reel (FacebookReels)
 *
 * The format is the variant's (`PostVariant::format()`).
 */
final class FacebookPagePublisher implements Publisher
{
    public function __construct(
        private readonly GraphClient $graph,
        private readonly FacebookReels $reels,
    ) {}

    public function supports(Platform $platform): bool
    {
        return $platform === Platform::FacebookPage;
    }

    public function publish(PostVariant $variant): PublishResult
    {
        $account = $variant->account;

        if ($account === null || blank($account->access_token)) {
            throw new PermanentPublishException('Variant has no connected Facebook Page (or the page token is missing).', 'no_account');
        }

        $graph = $this->graph->forVariant($variant);
        $token = (string) $account->access_token;
        $pageId = $account->external_id;

        $caption = $this->caption($variant);
        $media = $variant->media()->get();
        $format = $variant->format();

        if ($format === ContentFormat::Video) {
            if ($media->count() !== 1) {
                throw new PermanentPublishException('Reel je jedan video; priloženo je '.$media->count().' datoteka.', 'reel_needs_one_video');
            }

            return $this->reels->publish($variant, $pageId, $token, $media->first(), $caption);
        }

        if ($format === ContentFormat::Link || $media->isEmpty()) {
            $payload = array_filter(['message' => $caption, 'link' => $variant->link_url]);
            $response = $graph->post("{$pageId}/feed", $payload, $token, 'fb.feed');

            return $this->result($response['id'] ?? null, $response);
        }

        if ($media->count() === 1) {
            /** @var MediaAsset $asset */
            $asset = $media->first();
            $response = $graph->post("{$pageId}/photos", ['url' => $asset->publicUrl(), 'caption' => $caption], $token, 'fb.photo');

            return $this->result($response['post_id'] ?? $response['id'] ?? null, $response);
        }

        $attached = [];
        foreach ($media as $index => $asset) {
            $photo = $graph->post("{$pageId}/photos", ['url' => $asset->publicUrl(), 'published' => 'false'], $token, 'fb.photo.unpublished');
            $attached["attached_media[{$index}]"] = json_encode(['media_fbid' => $photo['id'] ?? null], JSON_THROW_ON_ERROR);
        }

        $response = $graph->post("{$pageId}/feed", ['message' => $caption, ...$attached], $token, 'fb.feed.multi');

        return $this->result($response['id'] ?? null, $response);
    }

    private function caption(PostVariant $variant): string
    {
        $caption = mb_trim($variant->caption);
        $limit = (int) config('hub.limits.fb_caption_chars', 63206);

        if (mb_strlen($caption) > $limit) {
            throw new PermanentPublishException("Caption is longer than {$limit} characters.", 'caption_too_long');
        }

        return $caption;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function result(?string $postId, array $raw): PublishResult
    {
        if (blank($postId)) {
            throw new PermanentPublishException('Graph API answered without a post id: '.json_encode($raw), 'no_post_id');
        }

        return new PublishResult((string) $postId, 'https://www.facebook.com/'.$postId, $raw);
    }
}
