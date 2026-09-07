<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('site_url')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('colors')->nullable();
            $table->json('voice')->nullable();
            $table->json('posting_windows')->nullable();
            $table->string('timezone', 64)->default('Europe/Zagreb');
            $table->timestamps();
        });

        Schema::create('sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('base_url', 2000)->nullable();
            $table->string('auth_type', 16)->default('bearer');
            $table->text('secret')->nullable();
            $table->json('config')->nullable();
            $table->timestamp('sync_since')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['brand_id', 'enabled']);
        });

        Schema::create('content_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 191);
            $table->string('kind', 16);
            $table->string('title', 300);
            $table->string('subtitle', 300)->nullable();
            $table->longText('body_text')->nullable();
            $table->json('facts');
            $table->json('badges');
            $table->json('price')->nullable();
            $table->json('cta')->nullable();
            $table->string('url', 2000);
            $table->json('images');
            $table->unsignedInteger('priority')->nullable();
            $table->json('tags');
            $table->json('raw')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('source_updated_at');
            $table->string('checksum', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
            $table->index(['brand_id', 'kind', 'priority']);
            $table->index('expires_at');
            $table->index('last_seen_at');
        });

        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('name');
            $table->string('external_id', 191);
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
            $table->index(['brand_id', 'platform', 'status']);
        });

        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('model', 64)->nullable();
            $table->string('status', 16)->default('running');
            $table->text('input_summary')->nullable();
            $table->json('output')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('post_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->default('single');
            $table->string('title', 300)->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('created_by_type', 16)->default('human');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['brand_id', 'status']);
        });

        Schema::create('post_draft_content_items', function (Blueprint $table): void {
            $table->foreignId('post_draft_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['post_draft_id', 'content_item_id']);
        });

        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 96);
            $table->json('params')->nullable();
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('format', 8);
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->unsignedInteger('bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('post_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_draft_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 16);
            $table->longText('caption');
            $table->string('link_url', 2000)->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 24)->default('pending');
            $table->uuid('idempotency_key')->unique();
            $table->string('external_post_id', 191)->nullable();
            $table->string('permalink', 2000)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('manual_posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manual_posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'platform']);
            $table->index('post_draft_id');
        });

        Schema::create('post_variant_media', function (Blueprint $table): void {
            $table->foreignId('post_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->primary(['post_variant_id', 'media_asset_id']);
        });

        Schema::create('publish_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_variant_id')->constrained()->cascadeOnDelete();
            $table->string('event', 48);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['post_variant_id', 'created_at']);
        });

        Schema::create('auto_publish_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->unsignedSmallInteger('daily_cap')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_publish_rules');
        Schema::dropIfExists('publish_logs');
        Schema::dropIfExists('post_variant_media');
        Schema::dropIfExists('post_variants');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('post_draft_content_items');
        Schema::dropIfExists('post_drafts');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('content_items');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('brands');
    }
};
