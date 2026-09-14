<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ScheduleDigest;
use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Hourly: build the recurring digest of every brand whose digest day and time have come.
 */
final class AutoDigest extends Command
{
    protected $signature = 'hub:auto-digest';

    protected $description = 'Build and schedule the recurring digest of brands that have one due';

    public function handle(ScheduleDigest $digests): int
    {
        foreach (Brand::query()->whereNotNull('digest')->get() as $brand) {
            $draft = $digests->execute($brand);

            if ($draft !== null) {
                $this->line("{$brand->name}: pregled #{$draft->id} zakazan za ".$draft->scheduled_at?->setTimezone($brand->timezone ?: 'Europe/Zagreb')->format('d.m.Y H:i'));
            }
        }

        return self::SUCCESS;
    }
}
