<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Catalog\CatalogCopy;
use App\Catalog\CatalogDemo;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Notifications\VoiceoverUnavailable;
use App\Rendering\CatalogVideo\CatalogVideoRenderer;
use App\Rendering\Scene;
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
 * Renders the "new catalog" video of a draft (CatalogVideoRenderer) and attaches it to the channels that wait for it.
 *
 * Its own job rather than a branch of RenderVideoJob, because it is not a slideshow of the item's cards. It keeps that
 * job's promises: the voice is an addition, never a condition — when it cannot be made (no key, a used-up plan, a
 * refused line) the video is rendered without it, the reason is kept on the asset for the review screen and an
 * admin is told once — and a failed render marks the channels instead of leaving them "rendering" for ever.
 */
final class RenderCatalogVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /**
     * @param  list<int>  $variantIds
     * @param  bool  $voiceover  Narrate with the brand's voice.
     * @param  string  $where  Where the link is, as the video says it: `comment` or `bio`.
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

    public function handle(CatalogVideoRenderer $video, Narrator $narrator, AdminNotifier $notifier): void
    {
        $draft = PostDraft::query()->with(['brand', 'contentItems'])->find($this->draftId);

        if ($draft === null || $draft->contentItems->isEmpty()) {
            return;
        }

        $brand = $draft->brand;
        $item = $draft->contentItems->first();

        try {
            $demo = CatalogDemo::fromItem($item);
            [$narration, $voiceFailure] = $this->voice($narrator, $notifier, $draft, $demo);

            $asset = $video->render(
                $brand,
                $draft,
                $item,
                $demo,
                $narration,
                $this->where,
                $brand->audioTrackPath($this->audio, $draft->id),
            );

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

        // TikTok takes the cover from a moment of the video, and its first frame is the whole phone with the title: the
        // moment after the first tap (the mark, the toast) is the one that says what the video is. A person's choice stays.
        $cover = (int) round((float) ($asset->params['timeline']['taps'][0]['at'] ?? 0) * 1000) + 500;

        foreach ($this->variants($draft) as $variant) {
            $variant->media()->sync([$asset->id => ['position' => 0]]);

            if ($variant->platform === Platform::TikTok && $variant->setting('cover_timestamp_ms') === null && $cover > 500) {
                $variant->putSettings(['cover_timestamp_ms' => $cover]);
            }

            $variant->markRendered();
        }
    }

    /**
     * The voice for these sentences, or why there is none. It never throws: a video without a voice is the
     * video the brand had before it had one.
     *
     * @return array{0: Narration|null, 1: VoiceoverException|null}
     */
    private function voice(Narrator $narrator, AdminNotifier $notifier, PostDraft $draft, CatalogDemo $demo): array
    {
        if (! $this->voiceover) {
            return [null, null];
        }

        $brand = $draft->brand;
        $cta = mb_trim((string) data_get($brand->voice, 'cta', ''));
        $lines = CatalogCopy::voiceLines($demo, $this->where, $cta === '' ? 'Preuzmi '.$brand->name : $cta);

        // Four sentences, four stretches of the video: the news, what to do, the call, where the link is. The call and the
        // link are the brand's own words, so they are held to the rules of a closing line, not checked against an offer.
        $scenes = [
            new Scene('catalog/news', Scene::COVER),
            new Scene('catalog/how', Scene::CARD),
            new Scene('catalog/call', Scene::CLOSING),
            new Scene('catalog/link', Scene::CLOSING),
        ];

        try {
            return [$narrator->narrateItems($brand, $draft->contentItems, null, $scenes, $lines), null];
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
     * The channels still waiting for a video — read after the render, not before. One switched to another
     * format meanwhile has its own media coming, and this video must not replace it.
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
