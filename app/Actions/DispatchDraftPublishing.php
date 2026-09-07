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
 */
final class DispatchDraftPublishing
{
    public function execute(PostDraft $draft): int
    {
        if (in_array($draft->status, [DraftStatus::Draft, DraftStatus::PendingApproval, DraftStatus::Discarded], true)) {
            throw new InvalidArgumentException("Draft #{$draft->id} is {$draft->status->value}; approve it first.");
        }

        $variants = $draft->variants()
            ->whereIn('status', [VariantStatus::Pending->value, VariantStatus::Failed->value])
            ->get();

        $claimedIds = [];

        foreach ($variants as $variant) {
            $claimed = PostVariant::query()
                ->whereKey($variant->id)
                ->whereIn('status', [VariantStatus::Pending->value, VariantStatus::Failed->value])
                ->update(['status' => VariantStatus::Queued->value, 'updated_at' => now()]);

            if ($claimed === 1) {
                $claimedIds[] = $variant->id;
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
