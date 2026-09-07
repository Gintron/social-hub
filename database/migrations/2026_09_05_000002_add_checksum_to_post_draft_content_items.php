<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of the item's checksum at the moment the draft was written, so the review screen can
     * tell that the source listing changed since the caption and image were produced.
     */
    public function up(): void
    {
        Schema::table('post_draft_content_items', function (Blueprint $table): void {
            $table->string('checksum', 64)->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('post_draft_content_items', function (Blueprint $table): void {
            $table->dropColumn('checksum');
        });
    }
};
