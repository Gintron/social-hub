<?php

declare(strict_types=1);

namespace App\Jobs;

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
            /** @var Collection<int, MediaAsset> $slides */
            $slides = $draft->contentItems->map(function (ContentItem $item) use ($images, $data, $templates, $brand, $draft): MediaAsset {
                $template = $this->templateKey ?? $templates->storyFor($item->kind);

                return $images->render($brand, $template, $data->forItem($item, $brand), $draft);
            });

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
