<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PostDraft;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use App\Rendering\TemplateRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders a whole carousel: a cover, then one slide per item, attached in that order.
 *
 * One job rather than several, because the order of the slides is the order of the images and
 * separate queued jobs finish in whatever order the workers happen to pick them up.
 */
final class RenderDigestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public readonly int $draftId,
        public readonly string $headline,
        public readonly ?string $kicker = null,
        public readonly string $coverTemplate = 'kinds/digest-cover',
        public readonly ?string $slideTemplate = null,
    ) {
        $this->onQueue('render');
    }

    public function handle(ImageRenderer $renderer, TemplateData $data, TemplateRegistry $templates): void
    {
        $draft = PostDraft::query()->with(['brand', 'contentItems', 'variants'])->find($this->draftId);

        if ($draft === null || $draft->contentItems->isEmpty()) {
            return;
        }

        $brand = $draft->brand;
        $items = $draft->contentItems;

        $assets = [$renderer->render($brand, $this->coverTemplate, $data->forDigest($items, $brand, $this->headline, $this->kicker), $draft)];

        // Instagram crops every slide to the first one's ratio, so the slides must match the cover.
        $cover = $templates->get($this->coverTemplate);
        $slideTemplate = $this->slideTemplate ?? $templates->defaultFor($items->first()->kind, $cover['height'] > $cover['width'] ? 'portrait' : 'square');

        foreach ($items as $item) {
            $assets[] = $renderer->render($brand, $slideTemplate, $data->forItem($item, $brand), $draft);
        }

        foreach ($draft->variants as $variant) {
            $variant->media()->detach();

            foreach ($assets as $position => $asset) {
                $variant->media()->attach($asset->id, ['position' => $position]);
            }
        }
    }
}
