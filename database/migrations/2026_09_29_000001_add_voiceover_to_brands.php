<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A brand's narrator: whether its videos are spoken, which ElevenLabs voice and model speak them,
     * how the brand's names are pronounced and what the closing line says. Off until a person turns
     * it on, so nothing changes for a brand that never opens the section.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->json('voiceover')->nullable()->after('audio_tracks');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('voiceover');
        });
    }
};
