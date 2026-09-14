<?php

declare(strict_types=1);

namespace App\Metrics\Contracts;

use App\Metrics\MetricsSnapshot;
use App\Models\PostVariant;

interface MetricsFetcher
{
    /**
     * The post's current numbers, or null when there is nothing to read yet (a TikTok post still
     * sitting in the creator's inbox has no public video to ask about).
     */
    public function fetch(PostVariant $variant): ?MetricsSnapshot;
}
