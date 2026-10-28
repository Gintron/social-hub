<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The short made from an approved script (App\Persona\ShortProducer): what fal was asked for and has delivered, so a
     * retry polls the same request instead of paying for it again, why it failed, and the post it became.
     */
    public function up(): void
    {
        Schema::table('topic_ideas', function (Blueprint $table): void {
            $table->json('production')->nullable()->after('script_error');
            $table->text('production_error')->nullable()->after('production');
            $table->foreignId('post_draft_id')->nullable()->after('production_error')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('topic_ideas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('post_draft_id');
            $table->dropColumn(['production', 'production_error']);
        });
    }
};
