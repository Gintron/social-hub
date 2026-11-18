<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Pinterest\PinBuilder;
use Illuminate\Console\Command;
use Throwable;

final class PinterestPins extends Command
{
    protected $signature = 'hub:pinterest-pins {brand? : The brand\'s slug; without it every brand of config/pinterest.php}';

    protected $description = 'Draw this week\'s Pinterest pins; the feeds Pinterest reads (/pinterest/{brand}/{board}.xml) pick them up';

    public function handle(PinBuilder $builder): int
    {
        $slugs = $this->argument('brand') !== null ? [(string) $this->argument('brand')] : array_keys((array) config('pinterest.brands', []));
        $failed = false;

        foreach ($slugs as $slug) {
            $brand = Brand::query()->where('slug', $slug)->first();

            if ($brand === null || ! is_array(config("pinterest.brands.{$slug}"))) {
                $this->error("Brend '{$slug}' nema Pinterest postavke.");
                $failed = true;

                continue;
            }

            try {
                $made = $builder->buildWeek($brand);
            } catch (Throwable $e) {
                // One brand's failed render (Chromium, an image that would not download) must not stop the others.
                report($e);
                $this->error("{$slug}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $this->info(sprintf('%s: %d novih pinova (tjedan %s).', $slug, count($made), PinBuilder::weekOf($brand, now())));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
