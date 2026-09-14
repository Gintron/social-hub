<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\CollectPostMetrics;
use App\Models\PostVariant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One post's numbers. A failure is logged and left for the next pass: the post is public already,
 * and missing one reading of its views is not worth a retry storm against the platform.
 */
final class CollectPostMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $variantId) {}

    public function uniqueId(): string
    {
        return (string) $this->variantId;
    }

    public function handle(CollectPostMetrics $metrics): void
    {
        $variant = PostVariant::query()->find($this->variantId);

        if ($variant === null) {
            return;
        }

        try {
            $metrics->execute($variant);
        } catch (Throwable $e) {
            Log::warning('hub.metrics.failed', ['variant' => $variant->id, 'platform' => $variant->platform->value, 'error' => $e->getMessage()]);
        }
    }
}
