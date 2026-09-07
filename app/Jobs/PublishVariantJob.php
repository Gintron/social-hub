<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Enums\VariantStatus;
use App\Models\PostVariant;
use App\Notifications\AccountNeedsReconnect;
use App\Notifications\PublishFailed;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Exceptions\RateLimitedException;
use App\Publishing\Exceptions\TokenInvalidException;
use App\Publishing\PublisherRegistry;
use App\Support\AdminNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes one variant. Idempotent: claims the variant atomically, never re-posts when an
 * external id already exists, and rolls the draft status up afterwards.
 */
final class PublishVariantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

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
        return [60, 300, 900];
    }

    public function handle(PublisherRegistry $publishers, AdminNotifier $notifier): void
    {
        $variant = PostVariant::query()->with(['account', 'draft.contentItems', 'media'])->find($this->variantId);

        if ($variant === null) {
            return;
        }

        $draft = $variant->draft;

        if ($variant->platform->isManual()) {
            if ($variant->status === VariantStatus::Queued) {
                $variant->forceFill(['status' => VariantStatus::ManualPending])->save();
                $draft?->refreshStatusFromVariants();
            }

            return;
        }

        if (filled($variant->external_post_id)) {
            if ($variant->status !== VariantStatus::Published) {
                $variant->forceFill(['status' => VariantStatus::Published, 'published_at' => $variant->published_at ?? now()])->save();
                $draft?->refreshStatusFromVariants();
            }

            return;
        }

        if (! $variant->claimForPublishing()) {
            return;
        }

        $expired = $draft?->contentItems->first(fn ($item): bool => $item->isExpired());
        if ($expired !== null) {
            $variant->forceFill([
                'status' => VariantStatus::Skipped,
                'error_code' => 'content_expired',
                'error_message' => "Stavka '{$expired->title}' je istekla ".$expired->expires_at?->diffForHumans().'; objava preskočena.',
            ])->save();
            $draft?->refreshStatusFromVariants();

            return;
        }

        if ($variant->account !== null && ! $variant->account->isUsable()) {
            $this->fail($variant, new TokenInvalidException("Račun {$variant->account->name} nije upotrebljiv (status {$variant->account->status->value})."), $notifier);
            $draft?->refreshStatusFromVariants();

            return;
        }

        try {
            $result = $publishers->for($variant->platform)->publish($variant);

            $variant->forceFill([
                'status' => VariantStatus::Published,
                'external_post_id' => $result->externalId,
                'permalink' => $result->permalink,
                'published_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();

            Log::info('hub.published', ['variant' => $variant->id, 'platform' => $variant->platform->value, 'permalink' => $result->permalink]);
        } catch (TokenInvalidException $e) {
            $variant->account?->forceFill(['status' => AccountStatus::NeedsReconnect])->save();

            if ($variant->account !== null) {
                $notifier->notify(new AccountNeedsReconnect($variant->account, $e->getMessage()));
            }

            $this->fail($variant, $e, $notifier);
        } catch (RateLimitedException $e) {
            $variant->forceFill(['status' => VariantStatus::Queued, 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()])->save();
            $this->release($e->retryAfterSeconds ?? 900);
        } catch (PublishException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                $variant->forceFill(['status' => VariantStatus::Queued, 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()])->save();
                $this->release($e->retryAfterSeconds ?? $this->backoff()[min($this->attempts(), 2)]);
            } else {
                $this->fail($variant, $e, $notifier);
            }
        } catch (Throwable $e) {
            $this->fail($variant, new PublishException($e->getMessage(), 'unexpected'), $notifier);

            throw $e;
        } finally {
            $draft?->refreshStatusFromVariants();
        }
    }

    private function fail(PostVariant $variant, PublishException $e, AdminNotifier $notifier): void
    {
        $variant->forceFill([
            'status' => VariantStatus::Failed,
            'error_code' => $e->errorCode,
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
        ])->save();

        Log::warning('hub.publish_failed', ['variant' => $variant->id, 'code' => $e->errorCode, 'message' => $e->getMessage()]);

        $notifier->notify(new PublishFailed($variant->fresh(['draft', 'account'])));
    }
}
