<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\VariantStatus;
use App\Models\PostVariant;
use InvalidArgumentException;

/**
 * A human pasted the prepared text into a Facebook group (no API exists); record that.
 */
final class MarkManualPosted
{
    public function execute(PostVariant $variant, ?int $userId, ?string $permalink = null): PostVariant
    {
        if (! $variant->platform->isManual()) {
            throw new InvalidArgumentException('Only manual channels can be marked as posted by hand.');
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
