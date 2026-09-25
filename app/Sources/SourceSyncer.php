<?php

declare(strict_types=1);

namespace App\Sources;

use App\Models\ContentItem;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls a source and upserts content items idempotently (unique source_id + external_id).
 */
final class SourceSyncer
{
    public function __construct(private readonly SourceRegistry $registry) {}

    public function sync(Source $source): SyncResult
    {
        $startedAt = CarbonImmutable::now();
        $since = $this->sinceFor($source);

        $created = $updated = $unchanged = 0;

        try {
            foreach ($this->registry->for($source)->fetch($source, $since) as $data) {
                $outcome = $this->upsert($source, $data, $startedAt);

                match ($outcome) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $unchanged++,
                };
            }
        } catch (Throwable $e) {
            $source->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        }

        $source->forceFill(['last_synced_at' => $startedAt, 'last_error' => null])->save();

        return new SyncResult($created, $updated, $unchanged);
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    private function upsert(Source $source, ContentItemData $data, CarbonImmutable $seenAt): string
    {
        return DB::transaction(function () use ($source, $data, $seenAt): string {
            $existing = ContentItem::query()
                ->where('source_id', $source->id)
                ->where('external_id', $data->externalId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                ContentItem::query()->create([
                    ...$data->toAttributes(),
                    'source_id' => $source->id,
                    'brand_id' => $source->brand_id,
                    'first_seen_at' => $seenAt,
                    'last_seen_at' => $seenAt,
                ]);

                return 'created';
            }

            if ($existing->checksum !== $data->checksum()) {
                // Seen again: the source vouches for the item, so its page gets checked afresh.
                $existing->fill([...$data->toAttributes(), 'last_seen_at' => $seenAt])->forceFill(['link_dead_at' => null])->save();

                return 'updated';
            }

            $existing->forceFill(['last_seen_at' => $seenAt, 'source_updated_at' => $data->updatedAt, 'link_dead_at' => null])->saveQuietly();

            return 'unchanged';
        });
    }

    private function sinceFor(Source $source): ?CarbonImmutable
    {
        if ($source->last_synced_at !== null) {
            return $source->last_synced_at->subMinutes((int) config('hub.sync.overlap_minutes', 60));
        }

        return $source->sync_since;
    }
}
