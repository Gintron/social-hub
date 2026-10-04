<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_publish_rules', function (Blueprint $table): void {
            // On by default: a rule made from now on drafts and renders by itself, but waits for a person
            // until somebody turns this off. The goal is full automation; the first videos are looked at.
            $table->boolean('requires_approval')->default(true)->after('enabled');
        });

        // Rules that existed before this switch were made to publish without anybody, and still do.
        DB::table('auto_publish_rules')->update(['requires_approval' => false]);
    }

    public function down(): void
    {
        Schema::table('auto_publish_rules', function (Blueprint $table): void {
            $table->dropColumn('requires_approval');
        });
    }
};
