<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\VariantStatus;
use App\Models\PostVariant;
use InvalidArgumentException;

/**
 * A human finished a post the hub could not: pasted the text into a Facebook group (no API exists),
 * or posted a video the hub handed to the TikTok inbox. Record that.
 */
final class MarkManualPosted
{
    public function execute(PostVariant $variant, ?int $userId, ?string $permalink = null): PostVariant
    {
        if (! $variant->platform->isManual() && $variant->status !== VariantStatus::ManualPending) {
            throw new InvalidArgumentException('Only manual channels, or variants waiting for a human, can be marked as posted by hand.');
        }

        $variant->forceFill([
            'status' => VariantStatus::ManualDone,
            'permalink' => $permalink,
            'published_at' => now(),
            'manual_posted_by' => $userId,
            'manual_posted_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $variant->draft?->refreshStatusFromVariants();

        return $variant;
    }
}
