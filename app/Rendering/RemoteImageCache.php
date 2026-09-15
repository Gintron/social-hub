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
        $file = $this->localFile($url);

        if ($file === null) {
            return null;
        }

        $binary = file_get_contents($file);
        $mime = $this->mime($file);

        if ($binary === false || $mime === null) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($binary);
    }

    /**
     * Width ÷ height of a source image, so a template can lay a logo out by its own shape: a
     * wordmark is about 5:1, a badge about 1:1, and at one height the badge reads half the mark.
     * Null when the shape cannot be read — the caller then treats it as it treats a wordmark.
     */
    public function aspectRatio(?string $url): ?float
    {
        $file = $this->localFile($url);

        if ($file === null) {
            return null;
        }

        $size = @getimagesize($file);

        if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
            return $size[0] / $size[1];
        }

        return $this->svgRatio($file);
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

    /**
     * The cached copy of a remote asset, downloaded on the first ask and kept for the TTL.
     */
    private function localFile(?string $url): ?string
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

        return $file;
    }

    /**
     * getimagesize() cannot measure an SVG, so its own header is read: the viewBox first (it is
     * what the mark is drawn in), then width and height when they carry plain numbers.
     */
    private function svgRatio(string $file): ?float
    {
        $head = file_get_contents($file, false, null, 0, 2048);

        if ($head === false || ! str_contains($head, '<svg')) {
            return null;
        }

        if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[,\s]+[-\d.]+[,\s]+([\d.]+)[,\s]+([\d.]+)/i', $head, $box) === 1) {
            return (float) $box[2] > 0 ? (float) $box[1] / (float) $box[2] : null;
        }

        $width = preg_match('/\bwidth\s*=\s*["\']([\d.]+)(?:px)?["\']/i', $head, $w) === 1 ? (float) $w[1] : 0.0;
        $height = preg_match('/\bheight\s*=\s*["\']([\d.]+)(?:px)?["\']/i', $head, $h) === 1 ? (float) $h[1] : 0.0;

        return $width > 0 && $height > 0 ? $width / $height : null;
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
