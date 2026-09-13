<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\ContentFormat;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\TransientPublishException;

/**
 * Does the media a variant carries match the format it is set to?
 *
 * The review screen shows the answer on every channel; PublishVariantJob asks the same question
 * right before a publisher runs, so a channel set to video never goes out with last week's image.
 * Platform-specific rules (Instagram's ratios, TikTok's durations) stay in their own preflights.
 */
final class FormatCheck
{
    /**
     * What stands between this variant and publishing, for a human; null when it is ready.
     */
    public function problem(PostVariant $variant): ?string
    {
        if ($variant->isRendering()) {
            return $variant->format()->label().' se još renderira.';
        }

        if (($error = $variant->renderError()) !== null) {
            return 'Render nije uspio: '.$error;
        }

        return $this->mismatch($variant);
    }

    /**
     * @throws TransientPublishException while the media is still rendering (the job retries)
     * @throws PermanentPublishException when the media does not fit the format
     */
    public function assertReady(PostVariant $variant): void
    {
        if ($variant->isRendering()) {
            throw new TransientPublishException($variant->format()->label().' se još renderira; objava čeka.', 'media_rendering');
        }

        // A failed re-render leaves the previous media in place; if that still fits, it may go out.
        $problem = $this->mismatch($variant);

        if ($problem !== null) {
            throw new PermanentPublishException($problem, 'media_not_ready');
        }
    }

    private function mismatch(PostVariant $variant): ?string
    {
        $format = $variant->format();

        if (! $format->needsMedia()) {
            return null;
        }

        $media = $variant->media()->get();
        $videos = $media->filter(fn (MediaAsset $asset): bool => $asset->isVideo())->count();
        $images = $media->count() - $videos;
        $maxSlides = $variant->platform->maxSlides();

        return match ($format) {
            ContentFormat::Video => match (true) {
                $videos === 0 => 'Video još nije renderiran.',
                $media->count() > 1 => "Video objava je jedan video; priloženo je {$media->count()} datoteka.",
                default => null,
            },
            ContentFormat::Image => match (true) {
                $media->isEmpty() => 'Slika još nije renderirana.',
                $videos > 0 => 'Priložen je video, a format je slika.',
                $images > 1 => "Priloženo je {$images} slika; za više slika odaberi carousel.",
                default => null,
            },
            ContentFormat::Carousel => match (true) {
                $videos > 0 => 'Carousel prima samo slike; priložen je video.',
                $images < 2 => 'Carousel treba barem 2 slajda; dodaj slajd.',
                $images > $maxSlides => "Carousel ima {$images} slajdova; {$variant->platform->label()} prima najviše {$maxSlides}.",
                default => null,
            },
            ContentFormat::Link => null,
        };
    }
}
