<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DraftStatus;
use App\Enums\VariantStatus;
use App\Jobs\PublishVariantJob;
use App\Models\PostDraft;
use App\Models\PostVariant;
use InvalidArgumentException;

/**
 * Moves every pending variant of an approved/scheduled draft to the publish queue (exactly once).
 *
 * `$anyway` is a person pressing "publish" on content that ran out: the variants the staleness check skipped
 * are queued again and told not to look at the expiry. Only a person says it; the scheduler and the agent do not.
 */
final class DispatchDraftPublishing
{
    public function execute(PostDraft $draft, bool $anyway = false): int
    {
        if (in_array($draft->status, [DraftStatus::Draft, DraftStatus::PendingApproval, DraftStatus::Discarded], true)) {
            throw new InvalidArgumentException("Draft #{$draft->id} is {$draft->status->value}; approve it first.");
        }

        $claimable = [VariantStatus::Pending->value, VariantStatus::Failed->value];

        $variants = $draft->variants()
            ->where(fn ($query) => $query
                ->whereIn('status', $claimable)
                ->when($anyway, fn ($query) => $query->orWhere(fn ($skipped) => $skipped
                    ->where('status', VariantStatus::Skipped->value)->where('error_code', 'content_expired'))))
            ->get();

        $claimedIds = [];

        foreach ($variants as $variant) {
            $claimed = PostVariant::query()
                ->whereKey($variant->id)
                ->where(fn ($query) => $query
                    ->whereIn('status', $claimable)
                    ->when($anyway, fn ($query) => $query->orWhere(fn ($skipped) => $skipped
                        ->where('status', VariantStatus::Skipped->value)->where('error_code', 'content_expired'))))
                ->update(['status' => VariantStatus::Queued->value, 'error_code' => null, 'error_message' => null, 'updated_at' => now()]);

            if ($claimed === 1) {
                $claimedIds[] = $variant->id;

                if ($anyway) {
                    $variant->putSettings(['publish_anyway' => true]);
                }
            }
        }

        if ($claimedIds === []) {
            return 0;
        }

        // Mark the draft before dispatching: with a synchronous queue the job finishes (and rolls the
        // draft status up) inside dispatch(), and a later save here would overwrite that result.
        $draft->forceFill(['status' => DraftStatus::Publishing])->save();

        foreach ($claimedIds as $variantId) {
            PublishVariantJob::dispatch($variantId);
        }

        return count($claimedIds);
    }
}
