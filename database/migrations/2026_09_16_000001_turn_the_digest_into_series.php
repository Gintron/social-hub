<?php

declare(strict_types=1);

use App\Drafting\DigestSeries;
use App\Enums\ActorType;
use App\Models\PostDraft;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            // Recurring roundups: [{key, enabled, name, headline, days, time, count, kind, tag, formats}].
            $table->json('digests')->nullable()->after('posting_windows');
        });

        Schema::table('post_drafts', function (Blueprint $table): void {
            // The series that built this digest; each series is built once a day.
            $table->string('digest_series', 40)->nullable()->after('kind');
        });

        foreach (DB::table('brands')->whereNotNull('digest')->get(['id', 'digest']) as $brand) {
            $series = DigestSeries::fromLegacy((array) json_decode((string) $brand->digest, true));

            if ($series === null) {
                continue;
            }

            DB::table('brands')->where('id', $brand->id)->update(['digests' => json_encode([$series])]);

            // A digest it already built today is this series' own, or it would build a second one.
            DB::table('post_drafts')
                ->where('brand_id', $brand->id)
                ->where('kind', PostDraft::KIND_DIGEST)
                ->where('created_by_type', ActorType::System->value)
                ->update(['digest_series' => $series['key']]);
        }

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('digest');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->json('digest')->nullable()->after('posting_windows');
        });

        foreach (DB::table('brands')->whereNotNull('digests')->get(['id', 'digests']) as $brand) {
            $first = ((array) json_decode((string) $brand->digests, true))[0] ?? null;

            if (is_array($first)) {
                DB::table('brands')->where('id', $brand->id)->update(['digest' => json_encode(array_intersect_key($first, array_flip(['enabled', 'days', 'time', 'count', 'kind'])))]);
            }
        }

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn('digests');
        });

        Schema::table('post_drafts', function (Blueprint $table): void {
            $table->dropColumn('digest_series');
        });
    }
};
