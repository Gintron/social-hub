<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Voiceover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class PruneVoiceovers extends Command
{
    protected $signature = 'hub:prune-voiceovers {--days=60 : Delete clips not used for this many days}';

    protected $description = 'Delete old spoken clips from disk; the record of what was said and paid for stays';

    public function handle(): int
    {
        $before = now()->subDays(max(1, (int) $this->option('days')));
        $files = 0;
        $bytes = 0;

        // The video is already rendered; a clip is only needed again if the same words are said again, and
        // then Synthesizer speaks them again under the same record. It costs a few characters, not a video.
        Voiceover::query()->where('updated_at', '<', $before)->each(function (Voiceover $clip) use (&$files, &$bytes): void {
            if (! $clip->fileExists()) {
                return;
            }

            $bytes += (int) $clip->bytes;
            $files++;
            Storage::disk($clip->disk)->delete($clip->path);
        });

        $this->info(sprintf('Obrisano %d zapisa (%.1f MB) starijih od %s.', $files, $bytes / 1_048_576, $before->format('d.m.Y.')));

        return self::SUCCESS;
    }
}
