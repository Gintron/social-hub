<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Catalog\CatalogCopy;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Notifications\VoiceoverUnavailable;
use App\Rendering\RecipeVideo\RecipeVideoRenderer;
use App\Rendering\ScenePlanner;
use App\Support\AdminNotifier;
use App\Voiceover\Narration;
use App\Voiceover\Narrator;
use App\Voiceover\VoiceoverException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renders the video of a dish as a scene in motion (RecipeVideoRenderer) and attaches it to the channels that wait for it.
 *
 * The brand chooses it over the slideshow (`voice.recipe_video = scene`, PrepareVariantMedia). It keeps the promises of the other
 * video jobs: the voice is an addition, never a condition — when it cannot be made the video is rendered without it, the reason is
 * kept on the asset and an admin is told once — and a failed render marks the channels instead of leaving them "rendering" for ever.
 */
final class RenderRecipeVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /**
     * @param  list<int>  $variantIds
     * @param  bool  $voiceover  Narrate with the brand's voice.
     * @param  string  $where  Where the link is, as the video says it: one of CatalogCopy::PLACES.
     * @param  string  $audio  `auto`, `none`, or an index into the brand's library (Brand::audioTrackPath).
     */
    public function __construct(
        public readonly int $draftId,
        public readonly array $variantIds,
        public readonly bool $voiceover = false,
        public readonly string $where = CatalogCopy::WHERE_COMMENT,
        public readonly string $audio = 'auto',
    ) {
        $this->onQueue('render');
    }

    public function handle(RecipeVideoRenderer $video, ScenePlanner $planner, Narrator $narrator, AdminNotifier $notifier): void
    {
        $draft = PostDraft::query()->with(['brand', 'contentItems'])->find($this->draftId);

        if ($draft === null || $draft->contentItems->isEmpty()) {
            return;
        }

        $brand = $draft->brand;
        $item = $draft->contentItems->first();

        try {
            [$narration, $voiceFailure] = $this->voice($narrator, $planner, $notifier, $draft);

            $asset = $video->render($brand, $draft, $item, $narration, $this->where, $brand->audioTrackPath($this->audio, $draft->id));

            if ($voiceFailure !== null) {
                $asset->update(['params' => [...($asset->params ?? []), 'voiceover' => [
                    'status' => 'failed',
                    'code' => $voiceFailure->errorCode,
                    'error' => mb_substr($voiceFailure->getMessage(), 0, 500),
                ]]]);
            }
        } catch (Throwable $e) {
            $this->variants($draft)->each(fn (PostVariant $variant) => $variant->markRenderFailed($e->getMessage()));

            throw $e;
        }

        // TikTok takes the cover from a moment of the video: the first dish once it has settled, with its sticker. A person's choice stays.
        $cover = (int) round(RecipeVideoRenderer::POSTER_AT * 1000);

        foreach ($this->variants($draft) as $variant) {
            $variant->media()->sync([$asset->id => ['position' => 0]]);

            if ($variant->platform === Platform::TikTok && $variant->setting('cover_timestamp_ms') === null) {
                $variant->putSettings(['cover_timestamp_ms' => $cover]);
            }

            $variant->markRendered();
        }
    }

    /**
     * The voice for the three sentences of a dish, or why there is none. The scenes are the slideshow's (hook, card, closing), so the
     * script, its checks and its stored clips are the same whichever video the brand chose. It never throws.
     *
     * @return array{0: Narration|null, 1: VoiceoverException|null}
     */
    private function voice(Narrator $narrator, ScenePlanner $planner, AdminNotifier $notifier, PostDraft $draft): array
    {
        if (! $this->voiceover) {
            return [null, null];
        }

        try {
            return [$narrator->narrate($draft, $planner->forDraft($draft), null, $this->where), null];
        } catch (Throwable $error) {
            $e = $error instanceof VoiceoverException ? $error : new VoiceoverException($error->getMessage(), 'error', previous: $error);

            Log::warning('hub.voiceover.failed', ['draft' => $draft->id, 'code' => $e->errorCode, 'message' => $e->getMessage()]);

            // A cause only a person can fix is worth one mail; a hiccup at ElevenLabs is not.
            if ($e->needsAttention() && Cache::add("voiceover.alert.{$draft->brand_id}.{$e->errorCode}", true, now()->addHours(12))) {
                $notifier->notify(new VoiceoverUnavailable($draft->brand, $e->errorCode, $e->getMessage()));
            }

            return [null, $e];
        }
    }

    /**
     * The channels still waiting for a video — read after the render, not before (as RenderCatalogVideoJob).
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
