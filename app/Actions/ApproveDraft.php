<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DraftStatus;
use App\Models\PostDraft;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ApproveDraft
{
    /**
     * Approve; when a time is given (or already set) the draft becomes scheduled.
     */
    public function execute(PostDraft $draft, ?int $approverId, ?CarbonImmutable $scheduledAt = null): PostDraft
    {
        if ($draft->status->isTerminal()) {
            throw new InvalidArgumentException("Draft #{$draft->id} is {$draft->status->value} and cannot be approved.");
        }

        if ($draft->variants()->count() === 0) {
            throw new InvalidArgumentException("Draft #{$draft->id} has no variants to publish.");
        }

        $scheduledAt ??= $draft->scheduled_at;

        $draft->forceFill([
            'status' => $scheduledAt !== null ? DraftStatus::Scheduled : DraftStatus::Approved,
            'scheduled_at' => $scheduledAt,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ])->save();

        return $draft;
    }
}
