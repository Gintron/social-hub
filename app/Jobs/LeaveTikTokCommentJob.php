<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Models\PostVariant;
use App\Notifications\TikTokCommentNeeded;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Exceptions\RateLimitedException;
use App\Publishing\Exceptions\TokenInvalidException;
use App\Publishing\Exceptions\TransientPublishException;
use App\Publishing\TikTok\Business\TikTokBusinessCommenter;
use App\Support\AdminNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Leaves the first comment on a TikTok post that has gone out — the one the video says the link is in.
 *
 * Its own job and not a step of publishing: TikTok makes the post public up to three minutes after it takes it, and
 * until then there is nothing to comment on. The post is already live, so nothing here can fail the variant. What
 * it can do is fail to comment — no permission yet, the post never became public, TikTok hid the comment as spam —
 * and then it does not stay silent: an admin gets the exact text to paste by hand (TikTokCommentNeeded).
 *
 * `settings.first_comment_status` says how it ended: `posted`, or `manual` (with `first_comment_reason`).
 */
final class LeaveTikTokCommentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** TikTok says post ids need up to three minutes. */
    public const FIRST_TRY_AFTER_MINUTES = 3;

    /** Eight tries, three to ten minutes apart: about half an hour for TikTok to make the post public. */
    public int $tries = 8;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $variantId)
    {
        $this->onQueue('publish');
    }

    public function uniqueId(): string
    {
        return (string) $this->variantId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [180, 180, 300, 300, 600, 600, 600];
    }

    public function handle(TikTokBusinessCommenter $commenter, AdminNotifier $notifier): void
    {
        $variant = PostVariant::query()->with('account')->find($this->variantId);
        $text = $variant === null ? '' : mb_trim((string) $variant->setting('first_comment', ''));

        if ($variant === null || $variant->platform !== Platform::TikTok || $text === '') {
            return;
        }

        // Done once: a retry of this job, or a second dispatch, never comments twice.
        if (in_array($variant->setting('first_comment_status'), ['posted', 'manual'], true)) {
            return;
        }

        if (! config('tiktok.business.comments') || config('tiktok.driver') !== 'business') {
            $this->manual($variant, 'not_enabled', $text, $notifier);

            return;
        }

        try {
            $postId = (string) ($variant->setting('tiktok_video_id') ?: $commenter->postId($variant) ?? '');

            if ($postId === '') {
                $this->waitOrGiveUp($variant, 'no_post_id', $text, $notifier);

                return;
            }

            $variant->putSettings(['tiktok_video_id' => $postId]);

            $commentId = $commenter->comment($variant, $postId, $text);
            $variant->putSettings(['first_comment_id' => $commentId, 'first_comment_status' => 'posted']);

            // TikTok says nothing when it hides a comment as spam; asking is the only way to know.
            if ($commenter->visibility($variant, $postId, $commentId) === 'HIDDEN') {
                $this->manual($variant, 'hidden', $text, $notifier);
            }
        } catch (RateLimitedException|TransientPublishException $e) {
            $this->waitOrGiveUp($variant, $e->errorCode, $text, $notifier);
        } catch (PublishException $e) {
            Log::warning('hub.tiktok.first_comment_failed', ['variant' => $variant->id, 'code' => $e->errorCode, 'message' => $e->getMessage()]);

            // A comment that went through and whose check failed is a comment: do not leave a second one.
            if ($variant->refresh()->setting('first_comment_status') === 'posted') {
                return;
            }

            $this->manual($variant, $this->looksLikePermission($e) ? 'permission' : $e->errorCode, $text, $notifier, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('hub.tiktok.first_comment_error', ['variant' => $variant->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Not yet: try again a little later, and after the last try hand it to a person.
     */
    private function waitOrGiveUp(PostVariant $variant, string $reason, string $text, AdminNotifier $notifier): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);

            return;
        }

        $this->manual($variant, $reason, $text, $notifier);
    }

    private function manual(PostVariant $variant, string $reason, string $text, AdminNotifier $notifier, ?string $detail = null): void
    {
        $variant->putSettings(['first_comment_status' => 'manual', 'first_comment_reason' => $detail === null ? $reason : mb_substr($reason.': '.$detail, 0, 300)]);
        $notifier->notify(new TikTokCommentNeeded($variant->refresh(), $reason, $text));
    }

    /**
     * TikTok names the missing permission in the message; its codes for it are not in the documentation.
     */
    private function looksLikePermission(PublishException $e): bool
    {
        return $e instanceof TokenInvalidException || preg_match('/permission|scope|authori[sz]|not allowed|forbidden/i', $e->getMessage()) === 1;
    }
}
