<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The language a brand's channels speak (App\Support\Wording): the hub's own words in its captions, the new-catalog
     * video and the voice-over. Every brand that exists is Croatian, so none of them changes.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('language', 8)->default('hr')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('language');
        });
    }
};
