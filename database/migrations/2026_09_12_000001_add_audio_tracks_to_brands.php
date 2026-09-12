<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TikTok's API cannot attach a sound from TikTok's own library, so the music under a video has to
     * be mixed in by the hub — from tracks the brand has the rights to.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->json('audio_tracks')->nullable()->after('voice');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('audio_tracks');
        });
    }
};
