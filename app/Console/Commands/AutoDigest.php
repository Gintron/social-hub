<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ScheduleDigest;
use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Hourly: build every digest series whose day and time have come.
 */
final class AutoDigest extends Command
{
    protected $signature = 'hub:auto-digest';

    protected $description = 'Build and schedule the recurring digests that are due';

    public function handle(ScheduleDigest $digests): int
    {
        foreach (Brand::query()->whereNotNull('digests')->get() as $brand) {
            foreach ($digests->execute($brand) as $draft) {
                $this->line("{$brand->name}: „{$draft->title}“ #{$draft->id} zakazan za ".$draft->scheduled_at?->setTimezone($brand->timezone ?: 'Europe/Zagreb')->format('d.m.Y H:i'));
            }
        }

        return self::SUCCESS;
    }
}
