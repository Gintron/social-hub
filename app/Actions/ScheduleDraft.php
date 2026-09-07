<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DraftStatus;
use App\Models\PostDraft;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ScheduleDraft
{
    public function execute(PostDraft $draft, CarbonImmutable $scheduledAt, ?int $approverId = null): PostDraft
    {
        if ($draft->status->isTerminal()) {
            throw new InvalidArgumentException("Draft #{$draft->id} is {$draft->status->value} and cannot be scheduled.");
        }

        if ($scheduledAt->isPast()) {
            throw new InvalidArgumentException('Scheduled time is in the past; use "publish now" instead.');
        }

        $draft->forceFill([
            'status' => DraftStatus::Scheduled,
            'scheduled_at' => $scheduledAt,
            'approved_by' => $draft->approved_by ?? $approverId,
            'approved_at' => $draft->approved_at ?? now(),
        ])->save();

        return $draft;
    }
}
