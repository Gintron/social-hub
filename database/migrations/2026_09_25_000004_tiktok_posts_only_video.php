<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every TikTok post is a video (Platform::formats()). The rules and digest series set up earlier
     * asked for photo posts, which TikTok's API for Business refuses — every one failed with
     * `tiktok_photo_unsupported` on 25 Sep 2026. The code falls back to video; this makes the saved
     * settings say what actually goes out.
     */
    public function up(): void
    {
        DB::table('auto_publish_rules')
            ->where('platform', 'tiktok')
            ->whereIn('format', ['image', 'carousel'])
            ->update(['format' => 'video', 'updated_at' => now()]);

        foreach (DB::table('brands')->whereNotNull('digests')->get(['id', 'digests']) as $brand) {
            $series = json_decode((string) $brand->digests, true);

            if (! is_array($series)) {
                continue;
            }

            $changed = false;

            foreach ($series as $index => $one) {
                if (is_array($one) && in_array($one['formats']['tiktok'] ?? 'carousel', ['image', 'carousel'], true)) {
                    $series[$index]['formats']['tiktok'] = 'video';
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('brands')->where('id', $brand->id)->update(['digests' => json_encode($series, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Which series were photo posts is not recorded, and TikTok gets video only.
    }
};
