<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Spatie\Image\Image;

/**
 * Blade template → Chromium screenshot (PNG) → JPEG on the public media disk.
 * JPEG because Instagram accepts nothing else; public disk because Meta fetches the URL.
 */
final class ImageRenderer
{
    public function __construct(
        private readonly TemplateRegistry $templates,
        private readonly TemplateData $data,
    ) {}

    /**
     * @param  array<string, mixed>  $item  View-model from TemplateData::forItem()
     */
    public function html(Brand $brand, string $templateKey, array $item): string
    {
        $template = $this->templates->get($templateKey);

        return View::make($template['view'], [
            'item' => $item,
            'brand' => $this->data->brandTheme($brand),
            'width' => $template['width'],
            'height' => $template['height'],
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function render(Brand $brand, string $templateKey, array $item, ?PostDraft $draft = null): MediaAsset
    {
        $template = $this->templates->get($templateKey);
        $html = $this->html($brand, $templateKey, $item);

        $png = tempnam(sys_get_temp_dir(), 'hub-render-').'.png';
        $jpg = tempnam(sys_get_temp_dir(), 'hub-render-').'.jpg';

        try {
            $this->browsershot($html, (int) $template['width'], (int) $template['height'])->save($png);

            Image::load($png)
                ->format('jpg')
                ->quality((int) config('hub.render.jpeg_quality', 90))
                ->save($jpg);

            $disk = (string) config('hub.media_disk', 'public');
            $path = sprintf('media/%s/%s/%s.jpg', $brand->slug, now()->format('Y/m'), Str::uuid());

            $binary = file_get_contents($jpg);
            if ($binary === false) {
                throw new RuntimeException('Rendered JPEG could not be read.');
            }

            Storage::disk($disk)->put($path, $binary, 'public');

            return MediaAsset::query()->create([
                'brand_id' => $brand->id,
                'post_draft_id' => $draft?->id,
                'template_key' => $templateKey,
                'params' => $this->storableParams($item),
                'width' => (int) $template['width'],
                'height' => (int) $template['height'],
                'format' => 'jpg',
                'disk' => $disk,
                'path' => $path,
                'bytes' => mb_strlen($binary),
                'checksum' => hash('sha256', $binary),
            ]);
        } finally {
            @unlink($png);
            @unlink($jpg);
        }
    }

    public function browsershot(string $html, int $width, int $height): Browsershot
    {
        $shot = Browsershot::html($html)
            ->windowSize($width, $height)
            ->deviceScaleFactor(1)
            ->showBackground()
            ->noSandbox()
            ->timeout((int) config('hub.render.timeout', 90))
            ->addChromiumArguments(['disable-dev-shm-usage', 'font-render-hinting=none']);

        if (filled(config('hub.render.node_binary'))) {
            $shot->setNodeBinary((string) config('hub.render.node_binary'));
        }

        if (filled(config('hub.render.npm_binary'))) {
            $shot->setNpmBinary((string) config('hub.render.npm_binary'));
        }

        if (filled(config('hub.render.chrome_path'))) {
            $shot->setChromePath((string) config('hub.render.chrome_path'));
        }

        return $shot;
    }

    /**
     * Keep params reproducible but small: data URIs are dropped (they can be rebuilt from the item).
     * Nested too — a provider's logo and a roundup's thumbnails sit inside arrays, and a few
     * megabytes of base64 per render have no business in the media_assets row.
     *
     * @param  array<array-key, mixed>  $item
     * @return array<array-key, mixed>
     */
    private function storableParams(array $item): array
    {
        foreach ($item as $key => $value) {
            $item[$key] = match (true) {
                is_array($value) => $this->storableParams($value),
                is_string($value) && str_starts_with($value, 'data:') => '[inline]',
                default => $value,
            };
        }

        return $item;
    }
}
