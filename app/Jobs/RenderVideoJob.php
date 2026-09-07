<?php

declare(strict_types=1);

namespace App\Jobs;

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
     */
    public function __construct(
        public readonly int $draftId,
        public readonly array $variantIds,
        public readonly float $secondsPerSlide = 3.0,
        public readonly ?string $templateKey = null,
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

        /** @var Collection<int, MediaAsset> $slides */
        $slides = $draft->contentItems->map(function (ContentItem $item) use ($images, $data, $templates, $brand, $draft): MediaAsset {
            $template = $this->templateKey ?? $templates->storyFor($item->kind);

            return $images->render($brand, $template, $data->forItem($item, $brand), $draft);
        });

        $asset = $video->slideshow($brand, $slides, $draft, $this->secondsPerSlide);

        $variants = PostVariant::query()->whereIn('id', $this->variantIds)->where('post_draft_id', $draft->id)->get();

        foreach ($variants as $variant) {
            $variant->media()->detach();
            $variant->media()->attach($asset->id, ['position' => 0]);
        }
    }
}
