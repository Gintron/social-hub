<?php

declare(strict_types=1);

namespace App\Rendering;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Downloads source images once and hands templates a data: URI, so Chromium never touches the
 * network while rendering (deterministic output, no CORS or timeout surprises).
 */
final class RemoteImageCache
{
    private const TTL_SECONDS = 7 * 24 * 3600;

    private const MAX_BYTES = 15 * 1024 * 1024;

    public function dataUri(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $file = $this->cachePath((string) $url);

        if (! is_file($file) || filemtime($file) < time() - self::TTL_SECONDS) {
            if (! $this->download((string) $url, $file)) {
                return null;
            }
        }

        $binary = file_get_contents($file);
        $mime = $this->mime($file);

        if ($binary === false || $mime === null) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    /**
     * Local file path for the given local or remote asset, e.g. brand logo stored on a disk.
     */
    public function dataUriFromPath(?string $path): ?string
    {
        if (blank($path) || ! is_file((string) $path)) {
            return null;
        }

        $binary = file_get_contents((string) $path);
        $mime = $this->mime((string) $path);

        return $binary !== false && $mime !== null ? 'data:'.$mime.';base64,'.base64_encode($binary) : null;
    }

    private function download(string $url, string $file): bool
    {
        try {
            $response = Http::timeout(20)->withUserAgent('social-hub/1.0')->get($url);
        } catch (ConnectionException $e) {
            Log::warning('hub.render.image_unreachable', ['url' => $url, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful() || mb_strlen($response->body()) > self::MAX_BYTES) {
            Log::warning('hub.render.image_rejected', ['url' => $url, 'status' => $response->status(), 'bytes' => mb_strlen($response->body())]);

            return false;
        }

        $dir = dirname($file);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($file, $response->body());

        return true;
    }

    private function cachePath(string $url): string
    {
        return storage_path('app/cache/images/'.sha1($url).'.bin');
    }

    private function mime(string $file): ?string
    {
        $mime = mime_content_type($file);

        return is_string($mime) && str_starts_with($mime, 'image/') ? $mime : null;
    }
}
