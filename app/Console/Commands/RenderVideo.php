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
        {--seconds=3 : Seconds per slide}';

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

        $started = microtime(true);

        try {
            $slides = $items->map(fn (ContentItem $item) => $images->render($brand, $templates->storyFor($item->kind), $data->forItem($item, $brand)));
            $asset = $video->slideshow($brand, $slides, secondsPerSlide: (float) $this->option('seconds'));
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

        return self::SUCCESS;
    }
}
