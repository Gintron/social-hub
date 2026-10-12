<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AutoLineup;
use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Fills the open posting window of every brand on the lineup. Syncs already do this when they bring
 * something in; this pass covers a window whose sync failed or brought nothing.
 */
final class RunAutoLineup extends Command
{
    protected $signature = 'hub:auto-lineup {--brand= : Slug or id of one brand}';

    protected $description = 'Fill the open posting window of each brand on the lineup with its best item';

    public function handle(AutoLineup $lineup): int
    {
        $brands = Brand::query()
            ->where('auto_lineup', true)
            ->when($this->option('brand'), fn ($query, $brand) => $query->where(fn ($inner) => $inner->where('id', $brand)->orWhere('slug', $brand)))
            ->get();

        foreach ($brands as $brand) {
            $created = $lineup->fill($brand);
            $this->line("{$brand->slug}: {$created} ".($created === 1 ? 'objava zakazana' : 'objava zakazano'));
        }

        return self::SUCCESS;
    }
}
