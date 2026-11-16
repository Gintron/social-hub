<?php

declare(strict_types=1);

namespace App\Rendering\RecipeVideo;

use App\Catalog\CatalogCopy;
use App\Catalog\CatalogVideoPlan;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Recipe\RecipeCopy;
use App\Recipe\RecipeInfo;
use App\Recipe\RecipeSceneBundle;
use App\Recipe\RecipeVideoPlan;
use App\Rendering\CatalogVideo\CatalogFrameRenderer;
use App\Rendering\CatalogVideo\CatalogVideoRenderer;
use App\Voiceover\Narration;
use App\Voiceover\NarrationClip;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The video of a dish as one scene in motion (resources/recipe-video): dishes over the whole frame from the first one, the app's
 * own screen with the dish, a tap, the list, the call. Chromium draws the frames, and the soundtrack and the encode are the
 * new-catalog video's (CatalogVideoRenderer), so both come out in the shape every uploader takes.
 *
 * It is the brand's choice (`voice.recipe_video = scene`) next to the slideshow of three still slides, and it says the same three
 * sentences (RecipeCopy::voiceLines): the voice is an addition, never a condition.
 */
final class RecipeVideoRenderer
{
    /** Stored as the asset's template_key, so a draft's scene video can be found and reused. */
    public const TEMPLATE_KEY = 'video/recipe-scene';

    /** The moment the cover is taken from: the first dish, settled, with its sticker. */
    public const POSTER_AT = 0.4;

    /** `voice.recipe_video`: the three still slides (the default, so a deploy changes nothing), or the scene. */
    public const STYLE_SLIDES = 'slides';

    public const STYLE_SCENE = 'scene';

    public function __construct(
        private readonly RecipeSceneBundle $bundles,
        private readonly CatalogFrameRenderer $frames,
        private readonly CatalogVideoRenderer $soundtrack,
    ) {}

    /**
     * Whether this brand tells a dish as the scene in motion. Anything but `scene` is the slideshow it had before.
     */
    public static function chosenBy(?Brand $brand): bool
    {
        return data_get($brand?->voice, 'recipe_video') === self::STYLE_SCENE;
    }

    /**
     * @param  string  $where  Where the link is, as the voice and the end card say it (CatalogCopy::WHERE_*).
     * @param  string|null  $audioPath  The brand's music; null leaves the voice and the tap alone on a silent bed.
     */
    public function render(
        Brand $brand,
        PostDraft $draft,
        ContentItem $item,
        ?Narration $narration = null,
        string $where = CatalogCopy::WHERE_COMMENT,
        ?string $audioPath = null,
    ): MediaAsset {
        if ($audioPath !== null && ! is_file($audioPath)) {
            throw new RuntimeException("Zvučni zapis ne postoji: {$audioPath}");
        }

        $info = RecipeInfo::fromItem($item);
        $lines = array_values(RecipeCopy::voiceLines($item, $brand, $where));

        if ($narration !== null && count($narration->clips) !== count($lines)) {
            throw new RuntimeException('Voice-over treba jedan redak po rečenici videa ('.count($lines).').');
        }

        $shape = $this->bundles->shape($item, $brand);
        $plan = RecipeVideoPlan::build(
            $lines,
            $narration === null ? null : array_map(fn (?NarrationClip $clip): ?float => $clip?->seconds, $narration->clips),
            $shape['dishes'],
            $shape['rows'],
        );

        $work = storage_path('app/tmp/recipe-video-'.Str::uuid());
        File::ensureDirectoryExists($work);

        try {
            $data = $this->bundles->make($brand, $item, $plan, $where, $work);
            $summary = $this->frames->frames($work, $work.'/frames');

            $mix = $this->soundtrack->mix($work, $plan, $narration, $audioPath);
            $output = $work.'/video.mp4';
            $this->soundtrack->encode($work, $mix, $output);

            $binary = file_get_contents($output);

            if ($binary === false || mb_strlen($binary, '8bit') < 1024) {
                throw new RuntimeException('ffmpeg je proizveo prazan video.');
            }

            $disk = (string) config('hub.media_disk', 'public');
            $path = sprintf('media/%s/%s/%s.mp4', $brand->slug, now()->format('Y/m'), Str::uuid());
            Storage::disk($disk)->put($path, $binary, 'public');

            $poster = $this->poster($brand, $draft, $work, $disk);

            return MediaAsset::query()->create([
                'brand_id' => $brand->id,
                'post_draft_id' => $draft->id,
                'template_key' => self::TEMPLATE_KEY,
                'params' => [
                    'recipe_id' => $info->recipeId,
                    'hook' => $data['hook']['style'],
                    'dishes' => count($data['hook']['dishes']),
                    'rows' => count($data['list']['rows']),
                    'link_in' => $where,
                    'audio' => $audioPath === null ? null : basename($audioPath),
                    'timeline' => Arr::only($plan, ['duration', 'swaps', 'hookEnd', 'tapAt', 'listAt', 'ctaAt']),
                    'frames' => $summary,
                    'mix' => $mix['loudness'],
                    'voiceover' => $narration === null ? null : [
                        ...$narration->describe(),
                        'starts' => array_map(fn (array $voice): float => $voice['at'], $plan['voice']),
                    ],
                ],
                'width' => CatalogVideoRenderer::WIDTH,
                'height' => CatalogVideoRenderer::HEIGHT,
                'duration_ms' => (int) round($plan['duration'] * 1000),
                'poster_media_asset_id' => $poster?->id,
                'format' => 'mp4',
                'disk' => $disk,
                'path' => $path,
                'bytes' => mb_strlen($binary, '8bit'),
                'checksum' => hash('sha256', $binary),
            ]);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * The cover: the first dish with its sticker and the question over it. Instagram takes it as the Reel's cover (`cover_url`);
     * Filament shows it before playback.
     */
    private function poster(Brand $brand, PostDraft $draft, string $work, string $disk): ?MediaAsset
    {
        $frame = (int) round(self::POSTER_AT * CatalogVideoPlan::FPS);
        $file = sprintf('%s/frames/%05d.jpg', $work, $frame);

        if (! is_file($file)) {
            return null;
        }

        $binary = (string) file_get_contents($file);
        $path = sprintf('media/%s/%s/%s.jpg', $brand->slug, now()->format('Y/m'), Str::uuid());
        Storage::disk($disk)->put($path, $binary, 'public');

        return MediaAsset::query()->create([
            'brand_id' => $brand->id,
            'post_draft_id' => $draft->id,
            'template_key' => 'video/recipe-poster',
            'params' => ['frame' => $frame],
            'width' => CatalogVideoRenderer::WIDTH,
            'height' => CatalogVideoRenderer::HEIGHT,
            'format' => 'jpg',
            'disk' => $disk,
            'path' => $path,
            'bytes' => mb_strlen($binary, '8bit'),
            'checksum' => hash('sha256', $binary),
        ]);
    }
}
