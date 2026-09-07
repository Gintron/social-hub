<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\DispatchDraftPublishing;
use App\Models\PostDraft;
use Illuminate\Console\Command;

final class PublishDue extends Command
{
    protected $signature = 'hub:publish-due';

    protected $description = 'Queue publishing for every scheduled draft whose time has come';

    public function handle(DispatchDraftPublishing $dispatch): int
    {
        $drafts = PostDraft::query()->due()->with('variants')->get();

        foreach ($drafts as $draft) {
            $queued = $dispatch->execute($draft);
            $this->line("Draft #{$draft->id}: queued {$queued} variant(s).");
        }

        if ($drafts->isEmpty()) {
            $this->line('Nothing due.');
        }

        return self::SUCCESS;
    }
}
