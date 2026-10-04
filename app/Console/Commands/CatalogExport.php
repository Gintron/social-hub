<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Catalog\PaidExport;
use App\Enums\Platform;
use App\Models\PostDraft;
use Illuminate\Console\Command;
use Throwable;

/**
 * The new-catalog video of a draft, collected for the network's promotion tool: video, cover, the ad's tracked link and
 * the post's text. Posts nothing, buys nothing.
 */
final class CatalogExport extends Command
{
    protected $signature = 'hub:catalog-export
        {draft : Id of the new-catalog draft}
        {--platform=tiktok : Channel whose video and link to export (tiktok, ig_business, fb_page)}
        {--out= : Folder (default storage/app/catalog-exports/<campaign>-<platform>)}';

    protected $description = 'Collect a new-catalog video, its paid tracked link and its text for TikTok Promote or Ads Manager';

    public function handle(PaidExport $export): int
    {
        $platform = Platform::tryFrom((string) $this->option('platform'));
        $draft = PostDraft::query()->find((int) $this->argument('draft'));

        if ($platform === null || $draft === null) {
            $this->error('Nepoznat nacrt ili kanal.');

            return self::FAILURE;
        }

        try {
            $result = $export->export($draft, $platform, filled($this->option('out')) ? (string) $this->option('out') : null);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($result['dir']);
        $this->line('Poveznica oglasa: '.$result['url']);
        $this->line('Datoteke: '.implode(', ', $result['files']));

        return self::SUCCESS;
    }
}
