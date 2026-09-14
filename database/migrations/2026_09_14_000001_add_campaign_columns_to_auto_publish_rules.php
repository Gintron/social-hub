<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_publish_rules', function (Blueprint $table): void {
            // Null = the platform's default format (Platform::defaultFormat()).
            $table->string('format', 20)->nullable()->after('daily_cap');
            // Variant settings the rule applies to every post it creates (delivery, share_to_feed, …).
            $table->json('settings')->nullable()->after('format');
        });

        Schema::table('sources', function (Blueprint $table): void {
            // Daily pass that schedules items the daily cap held back; off unless asked for.
            $table->boolean('auto_publish_backlog')->default(false)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('auto_publish_rules', function (Blueprint $table): void {
            $table->dropColumn(['format', 'settings']);
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn('auto_publish_backlog');
        });
    }
};
