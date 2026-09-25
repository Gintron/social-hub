<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A draft whose every channel was skipped (dead link, expired item) stayed "publishing" forever:
     * PostDraft::refreshStatusFromVariants() ignored skipped variants and, with none left, kept the
     * old status. It now becomes "skipped"; this settles the drafts that got stuck before.
     */
    public function up(): void
    {
        DB::table('post_drafts')
            ->where('status', 'publishing')
            ->whereExists(fn ($query) => $query->from('post_variants')
                ->whereColumn('post_variants.post_draft_id', 'post_drafts.id')
                ->where('post_variants.status', 'skipped'))
            ->whereNotExists(fn ($query) => $query->from('post_variants')
                ->whereColumn('post_variants.post_draft_id', 'post_drafts.id')
                ->whereNotIn('post_variants.status', ['skipped', 'disabled']))
            ->update(['status' => 'skipped', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('post_drafts')->where('status', 'skipped')->update(['status' => 'publishing']);
    }
};
