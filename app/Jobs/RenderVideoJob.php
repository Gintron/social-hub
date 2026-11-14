<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Catalog\CatalogCopy;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Notifications\VoiceoverUnavailable;
use App\Rendering\ImageRenderer;
use App\Rendering\Scene;
use App\Rendering\ScenePlanner;
use App\Rendering\TemplateData;
use App\Rendering\VideoRenderer;
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
 * Renders the draft's items as vertical slides and stitches them into one MP4, then attaches the
 * video to the given variants.
 *
 * Encoding takes tens of seconds, so this is deliberately its own job on the render queue rather
 * than something a request waits for.
 *
 * With `voiceover` the slides are narrated. The voice is an addition to the video, never a condition
 * of it: when it cannot be made (no key, a used-up plan, a refused script) the video is rendered
 * without it, the reason is kept on the asset for the review screen and an admin is told once, and
 * the post still goes out.
 */
final class RenderVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /*
     * Not promoted, with defaults: a render queued before the voice existed unserializes with them
     * (a silent video). A promoted readonly property would stay uninitialized and throw on first read.
     */

    /**
     * Narrate the slides with the brand's voice.
     */
    public bool $voiceover = false;

    /**
     * What to say instead of what is written from the items: one line per slide.
     *
     * @var list<string>|null
     */
    public ?array $script = null;

    /**
     * Where the link is, as the channel has it (CatalogCopy::PLACES). Only a recipe's end card and last sentence say it, so only
     * a recipe is rendered once per place; every other video is the one it always was.
     */
    public string $where = CatalogCopy::WHERE_COMMENT;

    /**
     * @param  list<int>  $variantIds
     * @param  string  $audio  `auto`, `none`, or an index into the brand's library (Brand::audioTrackPath).
     * @param  list<string>|null  $script
     */
    public function __construct(
        public readonly int $draftId,
        public readonly array $variantIds,
        public readonly float $secondsPerSlide = VideoRenderer::DEFAULT_SECONDS_PER_SLIDE,
        public readonly ?string $templateKey = null,
        public readonly string $audio = 'auto',
        public readonly bool $motion = true,
        bool $voiceover = false,
        ?array $script = null,
        string $where = CatalogCopy::WHERE_COMMENT,
    ) {
        $this->voiceover = $voiceover;
        $this->script = $script;
        $this->where = CatalogCopy::knownPlace($where) ?? CatalogCopy::WHERE_COMMENT;
        $this->onQueue('render');
    }

    public function handle(
        ImageRenderer $images,
        VideoRenderer $video,
        TemplateData $data,
        ScenePlanner $planner,
        Narrator $narrator,
        AdminNotifier $notifier,
    ): void {
        $draft = PostDraft::query()->with(['brand', 'contentItems'])->find($this->draftId);

        if ($draft === null || $draft->contentItems->isEmpty()) {
            return;
        }

        $brand = $draft->brand;

        try {
            $scenes = $planner->forDraft($draft, $this->templateKey);
            $slides = $this->render($scenes, $draft, $images, $data);

            [$narration, $voiceFailure] = $this->voice($narrator, $notifier, $draft, $scenes);

            $asset = $video->slideshow(
                $brand,
                $slides,
                $draft,
                $this->secondsPerSlide,
                audioPath: $brand->audioTrackPath($this->audio, $draft->id),
                motion: $this->motion,
                narration: $narration,
                // A quote is read, not glanced at: its card is held for as long as its text takes.
                minimumSeconds: array_map(fn (Scene $scene): ?float => $scene->readingSeconds(), $scenes),
            );

            // A recipe's video says where its link is, so a channel that says another place needs another file (PrepareVariantMedia).
            if ($draft->contentItems->first()?->kind === ContentKind::Recipe) {
                $asset->update(['params' => [...($asset->params ?? []), 'link_in' => $this->where]]);
            }

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

        foreach ($this->variants($draft) as $variant) {
            $variant->media()->sync([$asset->id => ['position' => 0]]);
            $variant->markRendered();
        }
    }

    /**
     * The voice for these scenes, or why there is none. It never throws: a video without a voice is the
     * video the brand had before it had one.
     *
     * @param  list<Scene>  $scenes
     * @return array{0: Narration|null, 1: VoiceoverException|null}
     */
    private function voice(Narrator $narrator, AdminNotifier $notifier, PostDraft $draft, array $scenes): array
    {
        if (! $this->voiceover) {
            return [null, null];
        }

        try {
            return [$narrator->narrate($draft, $scenes, $this->script, $this->where), null];
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
     * The scenes as rendered slides, in order (see ScenePlanner for what they are).
     *
     * @param  list<Scene>  $scenes
     * @return Collection<int, MediaAsset>
     */
    private function render(array $scenes, PostDraft $draft, ImageRenderer $images, TemplateData $data): Collection
    {
        $brand = $draft->brand;
        $items = [];
        $cover = null;

        return collect($scenes)->map(function (Scene $scene) use ($draft, $brand, $images, $data, &$items, &$cover): MediaAsset {
            $view = $scene->item !== null
                ? ($items[$scene->item->id] ??= $data->forItem($scene->item, $brand, ['link_in' => $this->where]))
                : ($cover ??= $data->forDigest($draft->contentItems, $brand, (string) $draft->title, $brand->name));

            return $images->render($brand, $scene->templateKey, $view, $draft);
        });
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
