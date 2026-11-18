<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Pinterest\PinBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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

            $problems = [];

            try {
                $made = $builder->buildWeek($brand, null, $problems);
            } catch (Throwable $e) {
                // One brand's failure (no site_url, Chromium missing) must not stop the others.
                report($e);
                $this->error("{$slug}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            foreach ($problems as $problem) {
                $this->error("{$slug}: {$problem}");
            }

            $this->info(sprintf('%s: %d novih pinova (tjedan %s).', $slug, count($made), PinBuilder::weekOf($brand, now())));

            // Nothing in the hub notifies anyone, so the run says it loudly: a week with no pin at all (no dishes on offer, every picture failing) leaves the
            // feeds as they were, and Pinterest then has nothing new to publish.
            if ($builder->pinsOfWeek($brand, now()) === 0) {
                Log::error('hub.pinterest.no_pins_this_week', ['brand' => $slug, 'problems' => $problems]);
                $this->error("{$slug}: ovaj tjedan nema nijednog pina, feedovi ostaju kakvi jesu.");
                $failed = true;
            } elseif ($problems !== []) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
