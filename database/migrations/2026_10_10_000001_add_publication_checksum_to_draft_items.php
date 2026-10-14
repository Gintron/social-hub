<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_draft_content_items', function (Blueprint $table): void {
            $table->string('publication_checksum', 64)->nullable()->after('checksum');
        });
    }

    public function down(): void
    {
        Schema::table('post_draft_content_items', function (Blueprint $table): void {
            $table->dropColumn('publication_checksum');
        });
    }
};
