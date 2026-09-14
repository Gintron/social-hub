<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\PrepareVariantMedia;
use App\Enums\ContentFormat;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use App\Rendering\TemplateRegistry;
use App\Rendering\VideoRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Renders the draft's items as vertical slides and stitches them into one MP4, then attaches the
 * video to the given variants.
 *
 * Encoding takes tens of seconds, so this is deliberately its own job on the render queue rather
 * than something a request waits for.
 */
final class RenderVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /**
     * @param  list<int>  $variantIds
     * @param  string  $audio  `auto`, `none`, or an index into the brand's library (Brand::audioTrackPath).
     */
    public function __construct(
        public readonly int $draftId,
        public readonly array $variantIds,
        public readonly float $secondsPerSlide = 3.0,
        public readonly ?string $templateKey = null,
        public readonly string $audio = 'auto',
        public readonly bool $motion = true,
    ) {
        $this->onQueue('render');
    }

    public function handle(
        ImageRenderer $images,
        VideoRenderer $video,
        TemplateData $data,
        TemplateRegistry $templates,
    ): void {
        $draft = PostDraft::query()->with(['brand', 'contentItems'])->find($this->draftId);

        if ($draft === null || $draft->contentItems->isEmpty()) {
            return;
        }

        $brand = $draft->brand;

        try {
            $slides = $this->slides($draft, $images, $data, $templates);

            $asset = $video->slideshow(
                $brand,
                $slides,
                $draft,
                $this->secondsPerSlide,
                audioPath: $brand->audioTrackPath($this->audio, $draft->id),
                motion: $this->motion,
            );
        } catch (Throwable $e) {
            $this->variants($draft)->each(fn (PostVariant $variant) => $variant->markRenderFailed($e->getMessage()));

            throw $e;
        }

        foreach ($this->variants($draft) as $variant) {
            $variant->media()->sync([$asset->id => ['position' => 0]]);
            $variant->markRendered();
        }
    }

    /**
     * What the video shows, in order. One item is told as its slide set (hook, card, call to
     * action) — a single three-second card is the shortest video a platform accepts and gives the
     * viewer no reason to stay. A digest opens with its cover and closes with the brand's end card.
     * An explicit template keeps the plain shape: one slide per item.
     *
     * @return Collection<int, MediaAsset>
     */
    private function slides(PostDraft $draft, ImageRenderer $images, TemplateData $data, TemplateRegistry $templates): Collection
    {
        $brand = $draft->brand;
        $items = $draft->contentItems;

        if ($this->templateKey !== null) {
            return $items->map(fn (ContentItem $item): MediaAsset => $images->render($brand, $this->templateKey, $data->forItem($item, $brand), $draft));
        }

        if ($draft->kind !== PostDraft::KIND_DIGEST) {
            $item = $items->first();
            $params = $data->forItem($item, $brand);

            return collect($templates->slidesFor($item->kind, 'story'))
                ->map(fn (string $key): MediaAsset => $images->render($brand, $key, $params, $draft));
        }

        $cover = $data->forDigest($items, $brand, (string) $draft->title, $brand->name);
        $slides = collect([$images->render($brand, PrepareVariantMedia::digestCover('story'), $cover, $draft)]);

        foreach ($items as $item) {
            $slides->push($images->render($brand, $templates->storyFor($item->kind), $data->forItem($item, $brand), $draft));
        }

        $closing = $templates->closingFor('story');

        if ($closing !== null) {
            $slides->push($images->render($brand, $closing, $cover, $draft));
        }

        return $slides;
    }

    /**
     * The channels still waiting for a video — read after the render, not before. One switched to
     * another format meanwhile has its own media coming, and this video must not replace it.
     *
     * @return Collection<int, PostVariant>
     */
    private function variants(PostDraft $draft): Collection
    {
        return PostVariant::query()->whereIn('id', $this->variantIds)->where('post_draft_id', $draft->id)->get()
            ->filter(fn (PostVariant $variant): bool => $variant->format() === ContentFormat::Video)
            ->values();
    }
}
