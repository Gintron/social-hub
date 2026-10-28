<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SuggestTopics;
use App\Ai\OpenAiException;
use App\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Hourly: every AI character whose morning has come gets the day's topics, once (SuggestTopics keeps it to one batch a
 * day). `--brand` asks now whatever the time, `--more` adds another batch to the day's.
 */
final class SuggestPersonaTopics extends Command
{
    protected $signature = 'hub:suggest-topics {--brand= : Slug brenda; bez toga svi likovi kojima je došlo jutro} {--more : Još jedna serija uz današnju}';

    protected $description = "Propose today's topics for the brands that are AI characters";

    public function handle(SuggestTopics $topics): int
    {
        $slug = $this->option('brand');
        $query = Brand::query()->whereNotNull('persona');

        if (is_string($slug) && $slug !== '') {
            $query->where('slug', $slug);
        }

        $failed = false;

        foreach ($query->get() as $brand) {
            $persona = $brand->personaSettings();

            // A character switched on but not described yet is half set up, not a failure of every hourly run.
            if (! $persona->enabled || (! $slug && ! $persona->isReady())) {
                continue;
            }

            $now = CarbonImmutable::now($brand->timezone ?: 'Europe/Zagreb');

            if (! $slug && $now->format('H:i') < $persona->suggestAt) {
                continue;
            }

            try {
                $ideas = $topics->execute($brand, more: (bool) $this->option('more'));
            } catch (InvalidArgumentException|OpenAiException $e) {
                $this->error("{$brand->name}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $this->line("{$brand->name}: ".$ideas->count().' tema za '.$now->format('d.m.Y'));

            foreach ($ideas as $idea) {
                $this->line("  #{$idea->id} {$idea->title}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
