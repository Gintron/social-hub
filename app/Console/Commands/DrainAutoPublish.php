<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ApplyAutoPublishRules;
use App\Models\Source;
use Illuminate\Console\Command;

/**
 * Morning pass for sources with "objavi i ono što je limit zadržao": schedules live items that no
 * post has used yet, up to each source's daily cap.
 */
final class DrainAutoPublish extends Command
{
    protected $signature = 'hub:auto-publish-backlog {--source= : Name or id of one source}';

    protected $description = 'Schedule live items the auto-publish daily cap held back';

    public function handle(ApplyAutoPublishRules $rules): int
    {
        $sources = Source::query()
            ->enabled()
            ->where('auto_publish_backlog', true)
            ->when($this->option('source'), fn ($query, $source) => $query->where(fn ($inner) => $inner->where('id', $source)->orWhere('name', $source)))
            ->get();

        foreach ($sources as $source) {
            $created = $rules->backlog($source);
            $this->line("{$source->name}: {$created} ".($created === 1 ? 'objava zakazana' : 'objava zakazano'));
        }

        return self::SUCCESS;
    }
}
