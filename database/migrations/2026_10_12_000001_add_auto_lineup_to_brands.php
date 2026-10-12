<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One automatic post per posting window, chosen by kind: a catalog first, then a comparison,
     * then a deal. Off by default, so no brand changes until a person switches it on.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->boolean('auto_lineup')->default(false)->after('daily_post_limit');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('auto_lineup');
        });
    }
};
