<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order in which the lineup takes kinds for a brand's posting windows, first kind first.
     * Null means the default order (catalog, comparison, deal); a kind left out of the list is not
     * posted by the lineup at all.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->json('lineup_priority')->nullable()->after('auto_lineup');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('lineup_priority');
        });
    }
};
