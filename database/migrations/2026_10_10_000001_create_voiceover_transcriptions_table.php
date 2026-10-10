<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a model writes the IPA of the words of a line (App\Voiceover\Phonetizer), kept per line, so a line is
     * transcribed once and the same text goes to the voice in every video.
     *
     * This creates a new table and leaves `voiceover_accents` alone: its rows are the stress marks of the earlier
     * mechanism, unused now, and dropping a table in production cannot be undone. Nothing reads or writes it.
     * Created only when missing, so a database that already has the table is not touched.
     */
    public function up(): void
    {
        if (Schema::hasTable('voiceover_transcriptions')) {
            return;
        }

        Schema::create('voiceover_transcriptions', function (Blueprint $table): void {
            $table->id();
            // sha256 of the line, the model that transcribed it and the version of the instructions.
            $table->char('hash', 64)->unique();
            $table->text('text');
            // [{"word": 3, "ipa": "ˈlɛtka"}]: the word by its number in the line and how it is said.
            // Empty when the model found nothing that a voice could say wrongly.
            $table->json('marks');
            $table->string('model', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voiceover_transcriptions');
    }
};
