<?php

declare(strict_types=1);

namespace App\Publishing\TikTok\Business;

use App\Models\PostVariant;
use App\Publishing\Exceptions\PermanentPublishException;

/**
 * The first comment of a TikTok post, through TikTok API for Business (Accounts API → Comments).
 *
 * Three calls, each documented in the portal: the status of the publishing task gives the id of the post
 * (`/business/publish/status/`, `post_ids`, ready up to three minutes after the post), `/business/comment/create/` leaves
 * the comment on it, and `/business/comment/list/` reads it back — because TikTok hides a comment it takes for spam
 * and says nothing when it does. Creating and reading need the comment permission, which the hub did not ask for
 * when the app was approved (docs/catalog-video.md).
 *
 * @see https://business-api.tiktok.com/portal/docs/create-a-new-comment-on-an-owned-video/v1.3
 * @see https://business-api.tiktok.com/portal/docs/get-the-publishing-status-of-a-tiktok-post/v1.3
 */
final class TikTokBusinessCommenter
{
    /** TikTok takes up to 1,200 characters (UTF-8) of text in a comment. */
    public const MAX_TEXT = 1200;

    public function __construct(private readonly TikTokBusinessClient $client) {}

    /**
     * The id of the published post, or null while TikTok has not made it public yet.
     *
     * @throws PermanentPublishException When TikTok says the publishing failed: there is no post to comment on.
     */
    public function postId(PostVariant $variant): ?string
    {
        [$client, $token, $businessId] = $this->context($variant);

        $data = $client->get('business/publish/status/', [
            'business_id' => $businessId,
            'publish_id' => (string) $variant->external_post_id,
        ], $token, 'tiktok.publish_status');

        $status = (string) ($data['status'] ?? '');

        if ($status === 'FAILED') {
            throw new PermanentPublishException('TikTok javlja da objava nije uspjela: '.($data['reason'] ?? 'bez razloga'), 'tiktok_publish_failed');
        }

        $ids = $status === 'PUBLISH_COMPLETE' ? (array) ($data['post_ids'] ?? []) : [];

        return filled($ids[0] ?? null) ? (string) $ids[0] : null;
    }

    /**
     * @return string The id of the comment.
     */
    public function comment(PostVariant $variant, string $postId, string $text): string
    {
        [$client, $token, $businessId] = $this->context($variant);

        $data = $client->post('business/comment/create/', [
            'business_id' => $businessId,
            'video_id' => $postId,
            'text' => mb_substr($text, 0, self::MAX_TEXT),
        ], $token, 'tiktok.comment');

        $id = $data['comment_id'] ?? null;

        if (blank($id)) {
            throw new PermanentPublishException('TikTok nije vratio comment_id: '.json_encode($data), 'tiktok_no_comment_id');
        }

        return (string) $id;
    }

    /**
     * Is the comment seen by everyone (`PUBLIC`) or hidden (`HIDDEN`)? Null when it cannot be told.
     */
    public function visibility(PostVariant $variant, string $postId, string $commentId): ?string
    {
        [$client, $token, $businessId] = $this->context($variant);

        $data = $client->get('business/comment/list/', [
            'business_id' => $businessId,
            'video_id' => $postId,
            // TikTok reads a list in a query string as JSON.
            'comment_ids' => json_encode([$commentId], JSON_THROW_ON_ERROR),
        ], $token, 'tiktok.comment_check');

        foreach ((array) ($data['comments'] ?? []) as $comment) {
            if ((string) ($comment['comment_id'] ?? '') === $commentId) {
                return filled($comment['status'] ?? null) ? (string) $comment['status'] : null;
            }
        }

        return null;
    }

    /**
     * @return array{0: TikTokBusinessClient, 1: string, 2: string} The client, the token and the business id.
     */
    private function context(PostVariant $variant): array
    {
        $account = $variant->account;

        if ($account === null || blank($account->access_token)) {
            throw new PermanentPublishException('Varijanta nema povezan TikTok račun (ili nedostaje token).', 'no_account');
        }

        $businessId = (string) ($account->meta['open_id'] ?? $account->external_id);

        if ($businessId === '') {
            throw new PermanentPublishException('TikTok račun nema open_id; poveži ga ponovno.', 'no_business_id');
        }

        return [$this->client->forVariant($variant), (string) $account->access_token, $businessId];
    }
}
