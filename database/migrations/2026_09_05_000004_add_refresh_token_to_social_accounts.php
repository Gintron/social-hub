<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TikTok access tokens live 24 hours and are traded in for new ones with a refresh token, which
     * is itself a credential and belongs in an encrypted column rather than in the `meta` JSON.
     */
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('refresh_token_expires_at')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn(['refresh_token', 'refresh_token_expires_at']);
        });
    }
};
