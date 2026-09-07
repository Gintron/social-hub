<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncSourceJob;
use App\Models\Source;
use App\Sources\SourceSyncer;
use Illuminate\Console\Command;
use Throwable;

final class SyncSources extends Command
{
    protected $signature = 'hub:sync-sources
        {--source= : Only this source (id or name)}
        {--now : Run inline instead of dispatching queue jobs}';

    protected $description = 'Pull new and changed items from every enabled Social Feed source';

    public function handle(SourceSyncer $syncer): int
    {
        $query = Source::query()->with('brand')->enabled()->pull();

        if (filled($this->option('source'))) {
            $key = (string) $this->option('source');
            $query->where(fn ($q) => $q->where('id', is_numeric($key) ? (int) $key : 0)->orWhere('name', $key));
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->warn('No enabled pull sources match.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($sources as $source) {
            if (! $this->option('now')) {
                SyncSourceJob::dispatch($source->id);
                $this->line("Queued sync for <info>{$source->name}</info>.");

                continue;
            }

            try {
                $result = $syncer->sync($source);
                $this->line(sprintf('<info>%s</info>: %d new, %d updated, %d unchanged', $source->name, $result->created, $result->updated, $result->unchanged));
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$source->name}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
