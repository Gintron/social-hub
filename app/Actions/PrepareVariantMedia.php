<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Jobs\RenderDigestJob;
use App\Jobs\RenderMediaJob;
use App\Jobs\RenderSlidesJob;
use App\Jobs\RenderVideoJob;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Rendering\ScenePlanner;
use App\Rendering\TemplateRegistry;
use App\Rendering\VideoRenderer;
use App\Voiceover\ScriptBuilder;
use App\Voiceover\ScriptGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use InvalidArgumentException;

/**
 * Gives each variant the media its own format needs — and only that variant.
 *
 * Media used to belong to the whole draft: rendering a video for TikTok replaced the image on
 * Facebook too. Now a variant reuses a matching asset the draft already has, or gets one rendered
 * in the background; variants that need the same render share one job.
 */
final class PrepareVariantMedia
{
    public function __construct(
        private readonly TemplateRegistry $templates,
        private readonly ScenePlanner $planner,
        private readonly ScriptBuilder $scripts,
        private readonly ScriptGuard $guard,
    ) {}

    /**
     * Vertical for TikTok, where a photo or video fills the screen; 4:5 portrait elsewhere, the
     * tallest image a Facebook or Instagram feed shows uncropped — more of the screen than a square.
     */
    public static function orientation(Platform $platform): string
    {
        return $platform === Platform::TikTok ? 'story' : 'portrait';
    }

    public static function digestCover(string $orientation): string
    {
        return $orientation === 'square' ? 'kinds/digest-cover' : "kinds/digest-cover-{$orientation}";
    }

    /**
     * @param  iterable<PostVariant>  $variants
     * @param  bool  $fresh  Render again even when a matching asset exists.
     * @param  string|null  $templateKey  Template for image and carousel variants; null picks one per channel.
     * @param  array{seconds?: float|int|string, audio?: string, motion?: bool, voiceover?: bool, script?: list<string>}  $video  `voiceover` overrides
     *                                                                                                                            what the channel and brand say; `script` is what to say instead of what is written from the items.
     * @param  bool  $sync  Render before returning (MCP tools answer with the new URLs).
     * @param  array<string, mixed>  $overrides  Template field overrides for image renders.
     * @param  string|null  $kicker  Line above a digest cover's headline; defaults to the brand name.
     */
    public function execute(
        iterable $variants,
        bool $fresh = false,
        ?string $templateKey = null,
        array $video = [],
        bool $sync = false,
        array $overrides = [],
        ?string $kicker = null,
    ): void {
        if ($templateKey !== null) {
            $this->templates->get($templateKey);
        }

        $variants = is_array($variants) ? $variants : iterator_to_array($variants, false);

        // A script somebody wrote is checked now, while they are still looking at the screen that sent it.
        if (is_array($video['script'] ?? null)) {
            $this->assertScript($variants, array_values($video['script']));
        }

        /** @var array<string, list<PostVariant>> $groups */
        $groups = [];

        foreach ($variants as $variant) {
            $variant->loadMissing(['draft.contentItems', 'draft.brand']);
            $draft = $variant->draft;
            $item = $draft?->contentItems->first();

            if ($draft === null || $item === null) {
                continue;
            }

            $format = $variant->format();
            $orientation = self::orientation($variant->platform);
            $isDigest = $draft->kind === PostDraft::KIND_DIGEST;

            if ($format === ContentFormat::Link) {
                $variant->media()->detach();
                $variant->markRendered();

                continue;
            }

            if ($format === ContentFormat::Video) {
                // The video with a voice and the one without are different files; a channel gets the one it asks for.
                $voice = array_key_exists('voiceover', $video) ? (bool) $video['voiceover'] : $variant->wantsVoiceover();
                $asset = $fresh ? null : $this->latestVideo($draft, $voice);
                $asset !== null ? $this->attach($variant, [$asset]) : $groups['video:'.($voice ? 'voice' : 'plain')][] = $variant;

                continue;
            }

            if ($isDigest && $templateKey === null) {
                if ($format === ContentFormat::Carousel) {
                    $set = $fresh ? null : $this->siblingCarousel($variant, $orientation);
                    $set !== null ? $this->attach($variant, $set) : $groups["digest:{$orientation}"][] = $variant;

                    continue;
                }

                $asset = $fresh ? null : $this->latest($draft, self::digestCover($orientation));
                $asset !== null ? $this->attach($variant, [$asset]) : $groups["digest-cover:{$orientation}"][] = $variant;

                continue;
            }

            // One item as a carousel is told in slides (hook, card, call to action), not one image.
            if ($format === ContentFormat::Carousel && $templateKey === null) {
                $set = $fresh ? null : $this->siblingCarousel($variant, $orientation);
                $set !== null ? $this->attach($variant, $set) : $groups["slides:{$orientation}"][] = $variant;

                continue;
            }

            $key = $templateKey ?? $this->templates->defaultFor($item->kind, $orientation);
            $asset = $fresh ? null : $this->latest($draft, $key);
            $asset !== null ? $this->attach($variant, [$asset]) : $groups["image:{$key}"][] = $variant;
        }

        foreach ($groups as $group => $members) {
            $this->dispatch($this->job($group, $members, $video, $overrides, $kicker), $members, $sync);
        }
    }

    /**
     * Render one more slide onto a carousel variant.
     */
    public function addSlide(PostVariant $variant, string $templateKey, bool $sync = false): void
    {
        $this->templates->get($templateKey);

        if ($variant->format() !== ContentFormat::Carousel) {
            throw new InvalidArgumentException('Slajd se dodaje samo kanalu u formatu carousel.');
        }

        $variant->loadMissing('draft.contentItems');
        $item = $variant->draft?->contentItems->first();

        if ($item === null) {
            throw new InvalidArgumentException('Nacrt nema stavku iz koje bi se renderirao slajd.');
        }

        $this->dispatch(new RenderMediaJob($variant->post_draft_id, $item->id, $templateKey, [$variant->id], replace: false), [$variant], $sync);
    }

    /**
     * @param  list<PostVariant>  $members
     * @param  array{seconds?: float|int|string, audio?: string, motion?: bool, voiceover?: bool, script?: list<string>}  $video
     * @param  array<string, mixed>  $overrides
     */
    private function job(string $group, array $members, array $video, array $overrides, ?string $kicker): ShouldQueue
    {
        $draft = $members[0]->draft;
        $ids = array_map(fn (PostVariant $variant): int => $variant->id, $members);
        [$type, $argument] = array_pad(explode(':', $group, 2), 2, '');

        return match ($type) {
            'video' => new RenderVideoJob(
                $draft->id,
                $ids,
                (float) ($video['seconds'] ?? VideoRenderer::DEFAULT_SECONDS_PER_SLIDE),
                audio: (string) ($video['audio'] ?? 'auto'),
                motion: (bool) ($video['motion'] ?? true),
                voiceover: $argument === 'voice',
                script: is_array($video['script'] ?? null) ? array_values(array_map('strval', $video['script'])) : null,
            ),
            'digest', 'digest-cover' => new RenderDigestJob(
                $draft->id,
                (string) $draft->title,
                $kicker ?? $draft->brand?->name,
                coverTemplate: self::digestCover($argument),
                variantIds: $ids,
                coverOnly: $type === 'digest-cover',
            ),
            'slides' => new RenderSlidesJob(
                $draft->id,
                $draft->contentItems->first()->id,
                $this->templates->slidesFor($draft->contentItems->first()->kind, $argument),
                $ids,
            ),
            default => new RenderMediaJob($draft->id, $draft->contentItems->first()->id, $argument, $ids, $overrides),
        };
    }

    /**
     * @param  list<PostVariant>  $members
     */
    private function dispatch(ShouldQueue $job, array $members, bool $sync): void
    {
        // Mark before dispatching: with a synchronous queue the job finishes (and clears the mark)
        // inside dispatch(), and a later write here would leave the variant "rendering" forever.
        foreach ($members as $variant) {
            $variant->markRendering();
        }

        $sync ? dispatch_sync($job) : dispatch($job);
    }

    private function latest(PostDraft $draft, string $templateKey): ?MediaAsset
    {
        return $draft->mediaAssets()->where('template_key', $templateKey)->latest('id')->first();
    }

    /**
     * The newest video of the draft that has a voice (or has none). One whose voice failed is a silent
     * video and counts as one, so a channel that wants a voice renders again instead of reusing it.
     */
    private function latestVideo(PostDraft $draft, bool $voice): ?MediaAsset
    {
        return $draft->mediaAssets()
            ->where('template_key', VideoRenderer::TEMPLATE_KEY)
            ->latest('id')
            ->get()
            ->first(fn (MediaAsset $asset): bool => (data_get($asset->params, 'voiceover.status') === 'ok') === $voice);
    }

    /**
     * @param  list<PostVariant>  $variants
     * @param  list<string>  $lines
     *
     * @throws InvalidArgumentException
     */
    private function assertScript(array $variants, array $lines): void
    {
        foreach (collect($variants)->map(fn (PostVariant $variant): ?PostDraft => $variant->loadMissing('draft')->draft)->filter()->unique('id') as $draft) {
            $draft->loadMissing('contentItems');
            $script = $this->scripts->fromLines($this->planner->forDraft($draft), $lines);
            $violations = $this->guard->check($script, $draft->contentItems, trusted: [(string) $draft->title]);

            if ($violations !== []) {
                throw new InvalidArgumentException('Tekst voice-overa nije prošao provjeru: '.implode(' ', $violations));
            }
        }
    }

    /**
     * A digest carousel another channel of the same orientation already carries, slides in order.
     *
     * @return list<MediaAsset>|null
     */
    private function siblingCarousel(PostVariant $variant, string $orientation): ?array
    {
        $siblings = PostVariant::query()
            ->with('media')
            ->where('post_draft_id', $variant->post_draft_id)
            ->whereKeyNot($variant->id)
            ->get();

        foreach ($siblings as $sibling) {
            if ($sibling->format() === ContentFormat::Carousel
                && self::orientation($sibling->platform) === $orientation
                && $sibling->media->count() >= 2
                && $sibling->media->every(fn (MediaAsset $asset): bool => ! $asset->isVideo())) {
                return $sibling->media->values()->all();
            }
        }

        return null;
    }

    /**
     * @param  list<MediaAsset>  $assets
     */
    private function attach(PostVariant $variant, array $assets): void
    {
        $variant->media()->sync(collect($assets)->values()->mapWithKeys(fn (MediaAsset $asset, int $position): array => [$asset->id => ['position' => $position]])->all());
        $variant->markRendered();
    }
}
