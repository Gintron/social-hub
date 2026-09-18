<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use App\Rendering\TemplateRegistry;
use App\Rendering\VideoRenderer;
use Illuminate\Console\Command;
use Throwable;

final class RenderVideo extends Command
{
    protected $signature = 'hub:render-video
        {--brand= : Brand slug (default: the brand of the first item)}
        {--kind=deal : Content kind to build slides from}
        {--count=3 : How many items}
        {--seconds=2 : Seconds per slide}
        {--audio=auto : auto (first track of the brand library), none, or a track index}
        {--no-motion : Keep slides still instead of a slow zoom}';

    protected $description = 'Render a 9:16 slideshow video from the newest items, the way Reels and TikTok want it';

    public function handle(ImageRenderer $images, VideoRenderer $video, TemplateData $data, TemplateRegistry $templates): int
    {
        $items = ContentItem::query()
            ->with('brand')
            ->where('kind', (string) $this->option('kind'))
            ->when(filled($this->option('brand')), fn ($query) => $query->whereHas('brand', fn ($q) => $q->where('slug', $this->option('brand'))))
            ->live()
            ->orderByDesc('priority')
            ->limit(max(1, (int) $this->option('count')))
            ->get();

        if ($items->isEmpty()) {
            $this->error('Nema stavki za video. Sinkroniziraj izvor ili promijeni --kind.');

            return self::FAILURE;
        }

        $brand = filled($this->option('brand'))
            ? Brand::query()->where('slug', (string) $this->option('brand'))->firstOrFail()
            : $items->first()->brand;

        $choice = (string) $this->option('audio');
        $audioPath = $brand->audioTrackPath($choice);

        if ($audioPath === null && $choice !== 'none') {
            $this->warn("Nema pjesme za --audio={$choice} u knjižnici brenda {$brand->slug}; video će biti bez zvuka.");
        }

        $started = microtime(true);

        try {
            // One item is told as its slide set, the same video a Reel of that item gets (RenderVideoJob).
            $slides = $items->count() === 1
                ? collect($templates->slidesFor($items->first()->kind, 'story'))
                    ->map(fn (string $key) => $images->render($brand, $key, $data->forItem($items->first(), $brand)))
                : $items->map(fn (ContentItem $item) => $images->render($brand, $templates->storyFor($item->kind), $data->forItem($item, $brand)));
            $asset = $video->slideshow(
                $brand,
                $slides,
                secondsPerSlide: (float) $this->option('seconds'),
                audioPath: $audioPath,
                motion: ! $this->option('no-motion'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Video %s (%dx%d, %.1fs, %d KB) za %.1fs',
            $asset->publicUrl(),
            $asset->width,
            $asset->height,
            (float) $asset->durationSeconds(),
            (int) (($asset->bytes ?? 0) / 1024),
            microtime(true) - $started,
        ));
        $this->line('Lokalno: '.$asset->absolutePath());
        $this->line('Slajdovi: '.$items->pluck('title')->implode(' · '));
        $this->line('Zvuk: '.($audioPath === null ? 'tišina' : basename($audioPath)));

        return self::SUCCESS;
    }
}
