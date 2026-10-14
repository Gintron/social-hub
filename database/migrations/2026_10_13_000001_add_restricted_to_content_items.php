<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Regulated goods the item shows or sells (`alcohol`), as the source declared them. Null for every
     * item synced before the field existed: those are judged by their words alone (RestrictedGoodsCheck).
     */
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->json('restricted')->nullable()->after('tags');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table): void {
            $table->dropColumn('restricted');
        });
    }
};
