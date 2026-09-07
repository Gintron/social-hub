<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyAutoPublishRules;
use App\Models\Source;
use App\Sources\SourceSyncer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SyncSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(SourceSyncer $syncer, ApplyAutoPublishRules $autoPublish): void
    {
        $source = Source::query()->with('brand')->find($this->sourceId);

        if ($source === null || ! $source->enabled || ! $source->type->isPull()) {
            return;
        }

        $result = $syncer->sync($source);

        Log::info('hub.sync', [
            'source' => $source->name,
            'created' => $result->created,
            'updated' => $result->updated,
            'unchanged' => $result->unchanged,
        ]);

        // Only new items can produce new drafts, so this is a no-op on a sync that changed nothing.
        if ($result->created > 0) {
            $autoPublish->execute($source);
        }
    }
}
