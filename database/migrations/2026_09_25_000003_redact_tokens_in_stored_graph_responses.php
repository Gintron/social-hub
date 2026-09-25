<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Graph echoes the Page token back in every `paging.next`/`previous` link, and post_metrics.raw
     * kept insights responses whole: the token sat in the database in plain text. The code now
     * redacts before storing (GraphClient::redact); this cleans what was stored before.
     */
    public function up(): void
    {
        foreach (['post_metrics' => ['raw'], 'publish_logs' => ['request', 'response']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)
                    ->select(['id', $column])
                    ->where(fn ($query) => $query->where($column, 'like', '%access_token%')->orWhere($column, 'like', '%appsecret_proof%'))
                    ->orderBy('id')
                    ->chunkById(200, function ($rows) use ($table, $column): void {
                        foreach ($rows as $row) {
                            DB::table($table)->where('id', $row->id)->update([$column => self::redact((string) $row->{$column})]);
                        }
                    });
            }
        }
    }

    public function down(): void
    {
        // A redacted token cannot be put back, and should not be.
    }

    private static function redact(string $json): string
    {
        $json = preg_replace('/\b(access_token|appsecret_proof)=[^&\s"\\\\]+/', '$1=[redacted]', $json) ?? $json;

        return preg_replace('/"(access_token|appsecret_proof)"\s*:\s*"[^"]*"/', '"$1": "[redacted]"', $json) ?? $json;
    }
};
