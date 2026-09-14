<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per capture, so a post's numbers can be followed over its first days, not just read once.
        Schema::create('post_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_variant_id')->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->unsignedBigInteger('views')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['post_variant_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metrics');
    }
};
