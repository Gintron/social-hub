<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ContentFormat;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use App\Rendering\TemplateRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Throwable;

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

    /*
     * Not promoted, with defaults: a job queued before these existed unserializes with the
     * defaults. A promoted readonly property would stay uninitialized and throw on first read.
     */

    /**
     * @var list<int>|null Channels to attach to; null means every image or carousel channel of the draft.
     */
    public ?array $variantIds = null;

    /**
     * Just the cover, for a channel that posts the digest as one image.
     */
    public bool $coverOnly = false;

    /**
     * @param  list<int>|null  $variantIds
     */
    public function __construct(
        public readonly int $draftId,
        public readonly string $headline,
        public readonly ?string $kicker = null,
        public readonly string $coverTemplate = 'kinds/digest-cover',
        public readonly ?string $slideTemplate = null,
        ?array $variantIds = null,
        bool $coverOnly = false,
    ) {
        $this->variantIds = $variantIds;
        $this->coverOnly = $coverOnly;
        $this->onQueue('render');
    }

    public function handle(ImageRenderer $renderer, TemplateData $data, TemplateRegistry $templates): void
    {
        $draft = PostDraft::query()->with(['brand', 'contentItems'])->find($this->draftId);

        if ($draft === null || $draft->contentItems->isEmpty()) {
            return;
        }

        $brand = $draft->brand;
        $items = $draft->contentItems;

        try {
            $assets = [$renderer->render($brand, $this->coverTemplate, $data->forDigest($items, $brand, $this->headline, $this->kicker), $draft)];

            if (! $this->coverOnly) {
                // Instagram crops every slide to the first one's ratio, so the slides must match the cover.
                $cover = $templates->get($this->coverTemplate);
                $ratio = $cover['height'] / max(1, $cover['width']);
                $orientation = match (true) {
                    $ratio > 1.5 => 'story',
                    $ratio > 1.0 => 'portrait',
                    default => 'square',
                };
                $slideTemplate = $this->slideTemplate ?? $templates->defaultFor($items->first()->kind, $orientation);

                foreach ($items as $item) {
                    $assets[] = $renderer->render($brand, $slideTemplate, $data->forItem($item, $brand), $draft);
                }
            }
        } catch (Throwable $e) {
            $this->variants($draft)->each(fn (PostVariant $variant) => $variant->markRenderFailed($e->getMessage()));

            throw $e;
        }

        foreach ($this->variants($draft) as $variant) {
            $variant->media()->detach();

            foreach ($assets as $position => $asset) {
                $variant->media()->attach($asset->id, ['position' => $position]);
            }

            $variant->markRendered();
        }
    }

    /**
     * The channels still waiting for this set — read after the render, not before, so one that
     * switched format meanwhile keeps the media its new format asked for.
     *
     * @return Collection<int, PostVariant>
     */
    private function variants(PostDraft $draft): Collection
    {
        $wanted = match (true) {
            // Queued before formats existed: every channel took the carousel.
            $this->variantIds === null => [ContentFormat::Image, ContentFormat::Carousel],
            $this->coverOnly => [ContentFormat::Image],
            default => [ContentFormat::Carousel],
        };

        return $draft->variants()->get()
            ->when($this->variantIds !== null, fn (Collection $variants): Collection => $variants->whereIn('id', $this->variantIds))
            ->filter(fn (PostVariant $variant): bool => in_array($variant->format(), $wanted, true))
            ->values();
    }
}
