<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ContentFormat;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Renders one item as a set of slides (hook, card, call to action) and attaches them in that order
 * to the carousel channels that asked for them.
 *
 * One job for the whole set, like RenderDigestJob: the order of the slides is the order of the
 * images, and separate jobs would finish in whatever order the workers pick them up.
 */
final class RenderSlidesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  list<string>  $templateKeys
     * @param  list<int>  $variantIds
     */
    public function __construct(
        public readonly int $draftId,
        public readonly int $contentItemId,
        public readonly array $templateKeys,
        public readonly array $variantIds,
    ) {
        $this->onQueue('render');
    }

    public function handle(ImageRenderer $renderer, TemplateData $data): void
    {
        $draft = PostDraft::query()->with('brand')->find($this->draftId);
        $item = ContentItem::query()->find($this->contentItemId);

        if ($draft === null || $item === null) {
            return;
        }

        try {
            $params = $data->forItem($item, $draft->brand);
            $assets = array_map(
                fn (string $key) => $renderer->render($draft->brand, $key, $params, $draft),
                $this->templateKeys,
            );
        } catch (Throwable $e) {
            $this->variants($draft)->each(fn (PostVariant $variant) => $variant->markRenderFailed($e->getMessage()));

            throw $e;
        }

        foreach ($this->variants($draft) as $variant) {
            $variant->media()->sync(collect($assets)->values()->mapWithKeys(fn ($asset, int $position): array => [$asset->id => ['position' => $position]])->all());
            $variant->markRendered();
        }
    }

    /**
     * The channels still posting a carousel — read after the render, so one that switched format
     * meanwhile keeps the media its new format asked for.
     *
     * @return Collection<int, PostVariant>
     */
    private function variants(PostDraft $draft): Collection
    {
        return PostVariant::query()->whereIn('id', $this->variantIds)->where('post_draft_id', $draft->id)->get()
            ->filter(fn (PostVariant $variant): bool => $variant->format() === ContentFormat::Carousel)
            ->values();
    }
}
