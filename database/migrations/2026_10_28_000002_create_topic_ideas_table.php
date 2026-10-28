<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an AI character could talk about today (App\Persona\TopicSuggester), and — once a person picks one — the
     * script of the short it becomes. `for_date` is the brand's local day the ideas were made for.
     */
    public function up(): void
    {
        Schema::create('topic_ideas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->date('for_date');
            $table->string('title', 200);
            $table->string('hook', 300);
            $table->text('angle');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('proposed');
            $table->json('script')->nullable();
            $table->text('script_error')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('chosen_at')->nullable();
            $table->timestamp('scripted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'for_date']);
            $table->index(['brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_ideas');
    }
};
