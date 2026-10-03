<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `characters` was filled from ElevenLabs' `character-cost` header, which is not a character count: it is
     * what the request was billed (14 for a 43-character line on multilingual v2, 3 on v4). From now on
     * `characters` is the length of the spoken text and the header lives in `cost`.
     *
     * Rows written until now: the old figure becomes the cost, unless it is exactly the length of the text, in
     * which case it was the stand-in used when the API sent no header and there is no cost to keep.
     */
    public function up(): void
    {
        Schema::table('voiceovers', function (Blueprint $table): void {
            $table->unsignedInteger('cost')->nullable()->after('characters');
        });

        DB::table('voiceovers')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $length = mb_strlen((string) $row->text);

                DB::table('voiceovers')->where('id', $row->id)->update([
                    'cost' => (int) $row->characters === $length ? null : (int) $row->characters,
                    'characters' => $length,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('voiceovers')->whereNotNull('cost')->update(['characters' => DB::raw('cost')]);

        Schema::table('voiceovers', function (Blueprint $table): void {
            $table->dropColumn('cost');
        });
    }
};
