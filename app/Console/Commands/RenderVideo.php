<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Rendering\ImageRenderer;
use App\Rendering\Scene;
use App\Rendering\TemplateData;
use App\Rendering\TemplateRegistry;
use App\Rendering\VideoRenderer;
use App\Voiceover\Narrator;
use Illuminate\Console\Command;
use Throwable;

final class RenderVideo extends Command
{
    protected $signature = 'hub:render-video
        {--brand= : Brand slug (default: the brand of the first item)}
        {--kind=deal : Content kind to build slides from}
        {--count=3 : How many items}
        {--seconds=3 : Seconds per slide}
        {--audio=auto : auto (first track of the brand library), none, or a track index}
        {--no-motion : Keep slides still instead of a slow zoom}
        {--voiceover : Narrate the slides with the brand\'s ElevenLabs voice}';

    protected $description = 'Render a 9:16 slideshow video from the newest items, the way Reels and TikTok want it';

    public function handle(ImageRenderer $images, VideoRenderer $video, TemplateData $data, TemplateRegistry $templates, Narrator $narrator): int
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
            // What the slides are, for the voice: the same plan the slides are drawn from.
            $headline = null;

            // One item is told as its slide set, the same video a Reel of that item gets (RenderVideoJob).
            if ($items->count() === 1) {
                $keys = $templates->slidesFor($items->first()->kind, 'story');
                $slides = collect($keys)->map(fn (string $key) => $images->render($brand, $key, $data->forItem($items->first(), $brand)));
                $scenes = array_map(fn (string $key): Scene => new Scene($key, $templates->roleOf($key), $items->first()), $keys);
            } elseif ((string) $this->option('kind') === 'job') {
                $headline = $items->count().' '.TemplateData::plural($items->count(), 'posao', 'posla', 'poslova');
                $cover = $data->forDigest($items, $brand, $headline, $brand->name);
                $slides = collect([$images->render($brand, 'kinds/job-digest-cover-story', $cover)]);
                $scenes = [new Scene('kinds/job-digest-cover-story', Scene::COVER)];

                foreach ($items as $item) {
                    $slides->push($images->render($brand, $templates->storyFor($item->kind), $data->forItem($item, $brand)));
                    $scenes[] = new Scene($templates->storyFor($item->kind), Scene::CARD, $item);
                }

                $slides->push($images->render($brand, 'kinds/job-cta-story', $cover));
                $scenes[] = new Scene('kinds/job-cta-story', Scene::CLOSING);
            } else {
                $slides = $items->map(fn (ContentItem $item) => $images->render($brand, $templates->storyFor($item->kind), $data->forItem($item, $brand)));
                $scenes = $items->map(fn (ContentItem $item): Scene => new Scene($templates->storyFor($item->kind), Scene::CARD, $item))->all();
            }

            // Asked for by name, so a voice that cannot be made is an error here, not a silent video.
            $narration = $this->option('voiceover') ? $narrator->narrateItems($brand, $items, $headline, $scenes) : null;

            $asset = $video->slideshow(
                $brand,
                $slides,
                secondsPerSlide: (float) $this->option('seconds'),
                audioPath: $audioPath,
                motion: ! $this->option('no-motion'),
                narration: $narration,
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

        if (($narration ?? null) !== null) {
            $this->line(sprintf('Voice-over: %d znakova, glas %s', $narration->characters(), $narration->voiceId));

            foreach ($narration->script->lines as $number => $line) {
                $this->line(sprintf('  %d. %s', $number + 1, $line->text !== '' ? $line->text : '(slajd samo uz glazbu)'));
            }
        }

        return self::SUCCESS;
    }
}
