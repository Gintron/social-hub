<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many posts automation may put out for a brand on one local day, whatever fills them
     * (single items, digests, several sources). Empty means no limit, so no brand changes until a
     * person sets one.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->unsignedTinyInteger('daily_post_limit')->nullable()->after('posting_windows');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('daily_post_limit');
        });
    }
};
