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
 * Renders one template for a draft's content item and attaches the image to the given variants.
 */
final class RenderMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    /**
     * @param  list<int>  $variantIds
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(
        public readonly int $draftId,
        public readonly int $contentItemId,
        public readonly string $templateKey,
        public readonly array $variantIds,
        public readonly array $overrides = [],
        public readonly bool $replace = true,
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
            $asset = $renderer->render($draft->brand, $this->templateKey, $data->forItem($item, $draft->brand, $this->overrides), $draft);
        } catch (Throwable $e) {
            // The review screen polls while a channel is rendering; tell it this one is not coming.
            $this->variants($draft)->each(fn (PostVariant $variant) => $variant->markRenderFailed($e->getMessage()));

            throw $e;
        }

        foreach ($this->variants($draft) as $variant) {
            if ($this->replace) {
                $variant->media()->detach();
            }

            $variant->media()->attach($asset->id, ['position' => $this->replace ? 0 : $variant->media()->count()]);
            $variant->markRendered();
        }
    }

    /**
     * The channels still posting images — read after the render, not before. One switched to
     * video or link meanwhile must not get this image back.
     *
     * @return Collection<int, PostVariant>
     */
    private function variants(PostDraft $draft): Collection
    {
        return PostVariant::query()->whereIn('id', $this->variantIds)->where('post_draft_id', $draft->id)->get()
            ->filter(fn (PostVariant $variant): bool => in_array($variant->format(), [ContentFormat::Image, ContentFormat::Carousel], true))
            ->values();
    }
}
