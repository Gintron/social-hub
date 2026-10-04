<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\PostDraft;
use App\Models\PostVariant;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * A new-catalog video ready for the network's own promotion tool (TikTok Promote, Ads Manager): the video as it was
 * posted, its cover, the ad's link — the same campaign as the organic post, `utm_medium=paid` — and the text of the
 * post to start from. Nothing is posted or bought; the hub only collects what a person pastes into the tool.
 *
 * Only a measured channel has such a link (TrackedLink). The export is a folder, so what is in it can be looked at.
 */
final class PaidExport
{
    /**
     * @return array{dir: string, url: string, video: string, files: list<string>}
     *
     * @throws InvalidArgumentException When the draft is not a new-catalog draft, or its channel has no finished video.
     */
    public function export(PostDraft $draft, Platform $platform = Platform::TikTok, ?string $into = null): array
    {
        $draft->loadMissing(['contentItems', 'variants.media.poster']);
        $item = $draft->contentItems->first();

        if ($item === null || $item->kind !== ContentKind::Catalog) {
            throw new InvalidArgumentException("Nacrt #{$draft->id} nije video kataloga.");
        }

        $variant = $draft->variants->first(fn (PostVariant $variant): bool => $variant->platform === $platform);
        $video = $variant?->media->first(fn ($asset): bool => $asset->isVideo());

        if ($variant === null || $video === null || ! is_file($video->absolutePath())) {
            throw new InvalidArgumentException("Nacrt #{$draft->id} nema gotov video za {$platform->label()}.");
        }

        $demo = CatalogDemo::fromItem($item);
        $link = TrackedLink::paid($demo, $platform);

        if ($link === null) {
            throw new InvalidArgumentException("{$platform->label()} nema mjerenu poveznicu: izvor nije dao adresu aplikacije i kampanju.");
        }

        $dir = $into ?? storage_path("app/catalog-exports/{$demo->campaign}-{$platform->value}");
        File::ensureDirectoryExists($dir);
        File::copy($video->absolutePath(), $dir.'/video.mp4');
        $files = ['video.mp4'];

        if ($video->poster !== null && is_file($video->poster->absolutePath())) {
            File::copy($video->poster->absolutePath(), $dir.'/naslovnica.jpg');
            $files[] = 'naslovnica.jpg';
        }

        File::put($dir.'/poveznica.txt', $link['url']."\n");
        File::put($dir.'/tekst.txt', mb_trim($variant->caption)."\n");
        File::put($dir.'/export.json', json_encode([
            'draft' => $draft->id,
            'platform' => $platform->value,
            'catalog' => $demo->catalogId,
            'chain' => $demo->chainName,
            'valid' => [$demo->validFrom->format('Y-m-d'), $demo->validTo->format('Y-m-d')],
            'utm' => ['source' => $link['source'], 'medium' => $link['medium'], 'campaign' => $link['campaign']],
            'url' => $link['url'],
            'duration_seconds' => $video->durationSeconds(),
            'voiceover' => data_get($video->params, 'voiceover.status') === 'ok',
            // Only the post's own label travels with it; an ad is labelled in the tool.
            'ai_generated' => $variant->aiGenerated(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $files = [...$files, 'poveznica.txt', 'tekst.txt', 'export.json'];

        return ['dir' => $dir, 'url' => $link['url'], 'video' => $dir.'/video.mp4', 'files' => $files];
    }
}
