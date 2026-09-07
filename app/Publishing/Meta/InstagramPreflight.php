<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Publishing\Exceptions\PermanentPublishException;
use Illuminate\Support\Collection;

/**
 * Everything Instagram rejects, checked before a single Graph call is made.
 *
 * Meta answers these with generic "Invalid parameter" errors that cost a container and tell you
 * nothing, so the hub validates its own media first and fails with a message a human can act on.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/content-publishing
 */
final class InstagramPreflight
{
    /**
     * Instagram accepts nothing else, whatever the file extension claims.
     */
    private const ALLOWED_MIME = 'image/jpeg';

    /**
     * Validate the caption and hand it back trimmed.
     */
    public function caption(PostVariant $variant): string
    {
        $caption = mb_trim($variant->caption);
        $limit = (int) config('hub.limits.ig_caption_chars', 2200);
        $length = mb_strlen($caption);

        if ($length > $limit) {
            throw new PermanentPublishException(
                "Tekst objave ima {$length} znakova, Instagram dopušta {$limit}.",
                'caption_too_long',
            );
        }

        $maxTags = (int) config('hub.limits.ig_hashtags', 30);
        $tags = preg_match_all('/#[\p{L}\p{N}_]+/u', $caption);

        if ($tags > $maxTags) {
            throw new PermanentPublishException(
                "Tekst ima {$tags} hashtagova, Instagram dopušta {$maxTags}.",
                'too_many_hashtags',
            );
        }

        return $caption;
    }

    /**
     * A reel is one video, vertical, between three seconds and fifteen minutes.
     *
     * @param  Collection<int, MediaAsset>  $media
     *
     * @throws PermanentPublishException
     */
    public function video(Collection $media): void
    {
        if ($media->count() !== 1) {
            throw new PermanentPublishException('Reel je jedan video; priloženo je '.$media->count().' datoteka.', 'reel_needs_one_video');
        }

        $asset = $media->first();

        if (! $asset->isVideo()) {
            throw new PermanentPublishException("Reel traži MP4; priloženo je {$asset->format}.", 'not_video');
        }

        $seconds = $asset->durationSeconds();

        if ($seconds !== null && ($seconds < 3 || $seconds > 900)) {
            throw new PermanentPublishException(
                sprintf('Video traje %.1f s; Instagram Reels prima između 3 s i 15 minuta.', $seconds),
                'bad_video_duration',
            );
        }

        $maxBytes = 1024 * 1024 * 1024;

        if ($asset->bytes !== null && $asset->bytes > $maxBytes) {
            throw new PermanentPublishException(sprintf('Video ima %.1f GB, dopušteno je 1 GB.', $asset->bytes / $maxBytes), 'video_too_large');
        }

        // Meta accepts a wide range, but a landscape reel is cropped to a vertical frame and the
        // content disappears — so the hub treats it as a mistake in how the draft was built.
        if ($asset->ratio() > 1.0) {
            throw new PermanentPublishException(
                sprintf('Video je %dx%d (omjer %.2f); Reels traži uspravan format, najbolje 9:16.', $asset->width, $asset->height, $asset->ratio()),
                'reel_not_vertical',
            );
        }

        $this->assertFetchableUrl($asset->publicUrl(), 'Video');
    }

    /**
     * @param  Collection<int, MediaAsset>  $media
     *
     * @throws PermanentPublishException
     */
    public function media(Collection $media): void
    {
        if ($media->isEmpty()) {
            throw new PermanentPublishException('Instagram objava mora imati sliku; nijedna nije priložena.', 'no_media');
        }

        if ($media->count() > 10) {
            throw new PermanentPublishException("Carousel prima najviše 10 slika, priloženo je {$media->count()}.", 'too_many_images');
        }

        foreach ($media as $index => $asset) {
            $this->assertAsset($asset, $index + 1);
        }

        // Instagram crops every slide to the first one's aspect ratio, so a mixed-ratio carousel
        // silently cuts off content instead of failing. Our own renderer controls the sizes, so a
        // mismatch here is a bug in how the draft was assembled, not something to let through.
        $ratios = $media->map(fn (MediaAsset $asset): string => sprintf('%.3f', $asset->ratio()))->unique();

        if ($ratios->count() > 1) {
            throw new PermanentPublishException(
                'Slike u carouselu nemaju isti omjer ('.$ratios->implode(', ').'); Instagram bi ih obrezao prema prvoj.',
                'mixed_ratios',
            );
        }
    }

    /**
     * Non-fatal warnings for the review screen (same rules, no exceptions).
     *
     * @param  Collection<int, MediaAsset>  $media
     * @return list<string>
     */
    public function warnings(PostVariant $variant, Collection $media): array
    {
        $warnings = [];
        $isReel = $variant->setting('format') === 'reel';

        foreach ([fn () => $this->caption($variant), fn () => $isReel ? $this->video($media) : $this->media($media)] as $check) {
            try {
                $check();
            } catch (PermanentPublishException $e) {
                $warnings[] = $e->getMessage();
            }
        }

        return $warnings;
    }

    private function assertAsset(MediaAsset $asset, int $position): void
    {
        $label = "Slika {$position}";

        if ($asset->format !== 'jpg') {
            throw new PermanentPublishException("{$label} je {$asset->format}; Instagram prima samo JPEG.", 'not_jpeg');
        }

        $maxBytes = (int) config('hub.limits.ig_image_max_bytes', 8 * 1024 * 1024);

        if ($asset->bytes !== null && $asset->bytes > $maxBytes) {
            throw new PermanentPublishException(
                sprintf('%s ima %.1f MB, dopušteno je %.0f MB.', $label, $asset->bytes / 1048576, $maxBytes / 1048576),
                'image_too_large',
            );
        }

        $ratio = $asset->ratio();
        $min = (float) config('hub.limits.ig_min_ratio', 0.8);
        $max = (float) config('hub.limits.ig_max_ratio', 1.91);

        if ($ratio < $min || $ratio > $max) {
            throw new PermanentPublishException(
                sprintf('%s ima omjer %.2f (%dx%d); Instagram traži između %.2f (4:5) i %.2f (1.91:1).', $label, $ratio, $asset->width, $asset->height, $min, $max),
                'bad_aspect_ratio',
            );
        }

        $this->assertFetchableUrl($asset->publicUrl(), $label);
    }

    /**
     * Meta cURLs the URL from its own servers at publish time: it has to be public https.
     */
    private function assertFetchableUrl(string $url, string $label): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($host) || ! is_string($scheme)) {
            throw new PermanentPublishException("{$label} nema valjan javni URL ({$url}).", 'bad_media_url');
        }

        if (app()->isProduction()) {
            if ($scheme !== 'https') {
                throw new PermanentPublishException("{$label} se poslužuje preko {$scheme}; Meta dohvaća medije samo preko https.", 'media_url_not_https');
            }

            if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')) {
                throw new PermanentPublishException("{$label} pokazuje na {$host}, kamo Meta ne može doći. Provjeri APP_URL.", 'media_url_not_public');
            }
        }
    }
}
