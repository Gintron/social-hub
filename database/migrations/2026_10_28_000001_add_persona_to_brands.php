<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An AI character the brand's channels belong to (App\Persona\Persona). Null for every brand that exists, so
     * none of them changes: a brand is a character only once a person switches it on in the panel.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->json('persona')->nullable()->after('digests');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('persona');
        });
    }
};
