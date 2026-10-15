<?php

declare(strict_types=1);

namespace App\Publishing\YouTube;

use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Support\Utf16;

/**
 * What YouTube refuses, or quietly turns into something else, checked before the first call.
 *
 * A vertical video of at most three minutes is a Short, and the hub makes only Shorts: a landscape
 * file would go up as an ordinary video. The caption is split the way TikTok reads it: the first line
 * is the title, the rest is the description.
 */
final class YouTubePreflight
{
    private const PRIVACY = ['public', 'unlisted', 'private'];

    /**
     * The title is the first line, trimmed; the description is everything after it, trimmed.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $caption): array
    {
        [$title, $description] = array_pad(preg_split('/\R/u', mb_trim($caption), 2) ?: [], 2, '');

        return [mb_trim((string) $title), mb_trim((string) $description)];
    }

    /**
     * The visibility this channel asks for, otherwise the hub's own (`youtube.privacy`).
     *
     * A value that was never chosen is no choice: the rule form saves an unfilled select as null and a cleared
     * one as '', and asking YouTube for visibility "" is what refused every Short such a rule made.
     */
    public static function privacy(PostVariant $variant): string
    {
        $chosen = $variant->setting('privacy');
        $chosen = is_scalar($chosen) ? mb_trim((string) $chosen) : '';

        return $chosen !== '' ? $chosen : (string) config('youtube.privacy', 'public');
    }

    /**
     * Stops here when the variant cannot become a Short; otherwise returns its one video.
     *
     * @throws PermanentPublishException
     */
    public function assert(PostVariant $variant): MediaAsset
    {
        $asset = $this->video($variant);

        [$title, $description] = self::split((string) $variant->caption);

        if ($title === '') {
            throw new PermanentPublishException('YouTube objava treba naslov: prvi red teksta.', 'empty_title');
        }

        $titleLimit = (int) config('youtube.title_chars', 100);
        $titleUnits = Utf16::length($title);

        if ($titleUnits > $titleLimit) {
            throw new PermanentPublishException("Naslov (prvi red teksta) ima {$titleUnits} znakova; YouTube dopušta {$titleLimit}.", 'title_too_long');
        }

        if (preg_match('/[<>]/', $title) === 1) {
            throw new PermanentPublishException('Naslov ne smije sadržavati znakove < i >.', 'title_invalid');
        }

        $descriptionLimit = (int) config('youtube.description_bytes', 5000);
        $descriptionBytes = mb_strlen($description, '8bit');

        if ($descriptionBytes > $descriptionLimit) {
            throw new PermanentPublishException("Opis ima {$descriptionBytes} bajtova; YouTube dopušta {$descriptionLimit}.", 'description_too_long');
        }

        $privacy = self::privacy($variant);

        if (! in_array($privacy, self::PRIVACY, true)) {
            throw new PermanentPublishException("Vidljivost '{$privacy}' nije poznata YouTubeu (public, unlisted ili private).", 'bad_privacy');
        }

        return $asset;
    }

    private function video(PostVariant $variant): MediaAsset
    {
        $media = $variant->media()->get();

        if ($media->count() !== 1) {
            throw new PermanentPublishException('YouTube Short je jedan video; priloženo je '.$media->count().' datoteka.', 'youtube_needs_one_video');
        }

        $asset = $media->first();

        if (! $asset->isVideo()) {
            throw new PermanentPublishException("YouTube prima MP4; priloženo je {$asset->format}.", 'not_video');
        }

        if (! is_file($asset->absolutePath())) {
            throw new PermanentPublishException('Video nije na disku; renderiraj ga ponovno.', 'media_missing');
        }

        $seconds = $asset->durationSeconds();
        $max = (int) config('youtube.max_seconds', 180);

        // An unknown duration is not refused: the render records it, and the file is the hub's own.
        if ($seconds !== null && $seconds > $max) {
            throw new PermanentPublishException(sprintf('Video traje %.1f s, a Short smije najviše %d s.', $seconds, $max), 'video_too_long');
        }

        if ($asset->height <= $asset->width) {
            throw new PermanentPublishException("Video je {$asset->width}×{$asset->height}; Short mora biti uspravan (9:16).", 'not_vertical');
        }

        return $asset;
    }
}
