<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Publishing\TikTok\Business\TikTokUrlProperties;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Verifies the hub's media folder as a TikTok URL prefix.
 *
 * A URL prefix is proven with a signature file served from the prefix itself, which the hub can
 * write on its own media disk — no DNS change, no person in the loop. The prefix is the `media/`
 * folder rather than the whole domain, so only files the hub rendered count as ours.
 */
final class TikTokUrlProperty extends Command
{
    protected $signature = 'hub:tiktok-url-property';

    protected $description = 'Verify the media folder as a TikTok URL property, so /business/video/publish/ accepts its video URLs';

    public function handle(TikTokUrlProperties $properties): int
    {
        $disk = (string) config('hub.media_disk', 'public');
        $prefix = $this->prefix($disk);

        if (! str_starts_with($prefix, 'https://') || preg_match('#^https://[^/]+:\d+/#', $prefix) === 1) {
            $this->error("TikTok prihvaća samo https bez porta, a medij se poslužuje s {$prefix}. Pokreni ovo u produkciji.");

            return self::FAILURE;
        }

        try {
            $property = collect($properties->list())
                ->first(fn (array $p): bool => $p['url'] === $prefix && $p['property_type'] === TikTokUrlProperties::TYPE_URL_PREFIX);

            if ($property !== null && $property['property_status'] === TikTokUrlProperties::STATUS_VERIFIED) {
                $this->info("{$prefix} je već verificiran.");

                return self::SUCCESS;
            }

            $property ??= $properties->add($prefix);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($property['file_name'] === '' || $property['signature'] === '') {
            $this->error('TikTok nije vratio ime datoteke i potpis: '.json_encode($property));

            return self::FAILURE;
        }

        // TikTok wants exactly one ".txt"; a doubled extension fails verification.
        $fileName = preg_replace('/(\.txt)+$/', '.txt', $property['file_name']) ?? $property['file_name'];
        Storage::disk($disk)->put('media/'.$fileName, $property['signature'], 'public');
        $this->line("Potpis zapisan: {$prefix}{$fileName}");

        if (! $this->servedCorrectly($prefix.$fileName, $property['signature'])) {
            return self::FAILURE;
        }

        try {
            $result = $properties->verify($prefix);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['property_status'] !== TikTokUrlProperties::STATUS_VERIFIED) {
            $this->error("TikTok nije potvrdio {$prefix} (status {$result['property_status']}). Datoteka ostaje na mjestu; pokreni ponovno.");

            return self::FAILURE;
        }

        $this->info("{$prefix} verificiran — video URL-ovi iz njega prolaze /business/video/publish/.");

        return self::SUCCESS;
    }

    private function prefix(string $disk): string
    {
        $url = Storage::disk($disk)->url('media/');

        if (! str_starts_with($url, 'http')) {
            $url = mb_rtrim((string) config('app.url'), '/').'/'.mb_ltrim($url, '/');
        }

        return mb_rtrim($url, '/').'/';
    }

    /**
     * TikTok does not follow redirects and gives no reason when a fetch fails, so check first.
     */
    private function servedCorrectly(string $url, string $signature): bool
    {
        try {
            $response = Http::withoutRedirecting()->timeout(15)->get($url);
        } catch (ConnectionException $e) {
            $this->error("{$url} nije dostupan: {$e->getMessage()}");

            return false;
        }

        if ($response->status() !== 200 || mb_trim($response->body()) !== $signature) {
            $this->error("{$url} vraća {$response->status()} umjesto potpisa — provjeri storage:link i da nema preusmjeravanja.");

            return false;
        }

        return true;
    }
}
