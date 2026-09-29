<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the stress falls in a line, decided once. A model that thinks does not answer the same way twice,
     * and a line that is marked differently is a different text to ElevenLabs — a clip that was already paid
     * for would be paid for again. So the first answer is kept and every later video reads it from here.
     */
    public function up(): void
    {
        Schema::create('voiceover_accents', function (Blueprint $table): void {
            $table->id();
            // sha256 of the line, the model that marked it and the version of the instructions.
            $table->char('hash', 64)->unique();
            $table->text('text');
            // [{"word": 3, "at": 1}]: the word by its number in the line, the vowel by its place in the word.
            // Empty when the model found nothing that a voice could stress wrongly.
            $table->json('marks');
            $table->string('model', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voiceover_accents');
    }
};
