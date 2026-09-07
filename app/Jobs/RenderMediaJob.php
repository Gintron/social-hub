<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

        $asset = $renderer->render($draft->brand, $this->templateKey, $data->forItem($item, $draft->brand, $this->overrides), $draft);

        $variants = PostVariant::query()->whereIn('id', $this->variantIds)->where('post_draft_id', $draft->id)->get();

        foreach ($variants as $variant) {
            if ($this->replace) {
                $variant->media()->detach();
            }

            $variant->media()->attach($asset->id, ['position' => $this->replace ? 0 : $variant->media()->count()]);
        }
    }
}
