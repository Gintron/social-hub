<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            // When the item's own page answered 404/410. A source that stops returning an item never
            // says so, so without this every roundup kept picking it and every post got skipped.
            // Cleared by the next sync that sees the item again.
            $table->timestamp('link_dead_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn('link_dead_at');
        });
    }
};
