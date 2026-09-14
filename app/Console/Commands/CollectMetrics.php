<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CollectPostMetrics;
use App\Jobs\CollectPostMetricsJob;
use App\Models\PostVariant;
use Illuminate\Console\Command;

/**
 * Queue a metrics read for every published post that is due one (see CollectPostMetrics::due()).
 */
final class CollectMetrics extends Command
{
    protected $signature = 'hub:collect-metrics
        {--variant= : Read one variant now, due or not}
        {--now : Run in this process instead of queueing}';

    protected $description = 'Read views, reach and interactions of published posts';

    public function handle(CollectPostMetrics $metrics): int
    {
        $variants = filled($this->option('variant'))
            ? PostVariant::query()->whereKey($this->option('variant'))->get()
            : $metrics->due();

        foreach ($variants as $variant) {
            $this->option('now')
                ? CollectPostMetricsJob::dispatchSync($variant->id)
                : CollectPostMetricsJob::dispatch($variant->id);
        }

        $this->line($variants->count().' '.($variants->count() === 1 ? 'objava' : 'objava').' za očitati.');

        return self::SUCCESS;
    }
}
