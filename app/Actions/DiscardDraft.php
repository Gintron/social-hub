<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DraftStatus;
use App\Enums\VariantStatus;
use App\Models\PostDraft;
use InvalidArgumentException;

final class DiscardDraft
{
    public function execute(PostDraft $draft): PostDraft
    {
        if (in_array($draft->status, [DraftStatus::Publishing, DraftStatus::Published], true)) {
            throw new InvalidArgumentException("Draft #{$draft->id} is {$draft->status->value} and cannot be discarded.");
        }

        $draft->variants()->whereNotIn('status', [VariantStatus::Published->value, VariantStatus::ManualDone->value])
            ->update(['status' => VariantStatus::Disabled->value]);

        $draft->forceFill(['status' => DraftStatus::Discarded])->save();

        return $draft;
    }
}
