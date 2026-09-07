<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Source;
use App\Sources\ContentItemData;
use App\Sources\Exceptions\SourceException;
use App\Sources\SourceRegistry;
use Illuminate\Console\Command;

final class SourceTest extends Command
{
    protected $signature = 'hub:source-test {source : Source id or name}';

    protected $description = 'Fetch one small page from a source, validate it against Social Feed v1 and show samples';

    public function handle(SourceRegistry $registry): int
    {
        $key = (string) $this->argument('source');

        $source = Source::query()->with('brand')
            ->where(fn ($q) => $q->where('id', is_numeric($key) ? (int) $key : 0)->orWhere('name', $key))
            ->first();

        if ($source === null) {
            $this->error("No source '{$key}'.");

            return self::FAILURE;
        }

        try {
            $result = $registry->for($source)->test($source);
        } catch (SourceException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Feed OK — brand '{$result->brand}', {$result->itemCount} item(s) in the first page".($result->nextCursor ? ', more pages available' : '').'.');

        if ($result->samples !== []) {
            $this->table(
                ['id', 'kind', 'title', 'priority', 'images', 'expires_at'],
                array_map(fn (ContentItemData $item): array => [
                    $item->externalId,
                    $item->kind->value,
                    mb_substr($item->title, 0, 50),
                    $item->priority ?? '-',
                    count($item->images),
                    $item->expiresAt?->toDateString() ?? '-',
                ], $result->samples),
            );
        }

        foreach ($result->warnings as $warning) {
            $this->warn('⚠ '.$warning);
        }

        return self::SUCCESS;
    }
}
