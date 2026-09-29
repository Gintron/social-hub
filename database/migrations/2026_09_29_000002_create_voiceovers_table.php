<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every clip ElevenLabs has spoken for us, once. It is the cache (the same words in the same voice
     * are never paid for twice — a brand's closing line is spoken once for every video that follows)
     * and the ledger: what was said, in which voice, and how many characters it cost.
     */
    public function up(): void
    {
        Schema::create('voiceovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            // sha256 of the spoken text and everything that changes how it sounds.
            $table->char('hash', 64)->unique();
            $table->string('voice_id', 64);
            $table->string('model', 64);
            $table->text('text');
            $table->json('settings')->nullable();
            $table->unsignedInteger('characters');
            $table->unsignedInteger('duration_ms');
            // As returned, before any gain: what a render levels the clip from.
            $table->float('loudness_lufs')->nullable();
            $table->float('true_peak_db')->nullable();
            $table->string('disk', 32);
            $table->string('path');
            $table->unsignedInteger('bytes')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voiceovers');
    }
};
