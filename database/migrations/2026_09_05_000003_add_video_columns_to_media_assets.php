<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Video needs two things an image never did: how long it runs (every platform has a minimum and
     * a maximum) and which still frame represents it in a feed.
     */
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->unsignedInteger('duration_ms')->nullable()->after('height');
            $table->foreignId('poster_media_asset_id')->nullable()->after('duration_ms')
                ->constrained('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('poster_media_asset_id');
            $table->dropColumn('duration_ms');
        });
    }
};
