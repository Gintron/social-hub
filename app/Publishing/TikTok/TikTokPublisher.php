<?php

declare(strict_types=1);

namespace App\Publishing\TikTok;

use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Publishing\Contracts\Publisher;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\TransientPublishException;
use App\Publishing\PublishResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Direct Post to TikTok.
 *
 * The order is fixed by TikTok's own rules: ask what the creator allows, post within those limits,
 * then poll until the upload is really live. Asking first is not politeness — the allowed privacy
 * levels differ per creator, and an app that has not passed TikTok's audit may only post privately.
 *
 * Variant settings: `privacy_level`, `disable_comment`, `disable_duet`, `disable_stitch`,
 * `cover_timestamp_ms`, `delivery` (`direct` | `inbox`), `auto_add_music` (photo posts, default true).
 *
 * The variant's format decides the flow: video, or a photo post for image and carousel.
 *
 * `delivery = inbox` uploads the video as a draft into the creator's TikTok inbox instead. The API
 * cannot attach a sound from TikTok's library; in the app a human can, then posts it and marks the
 * variant done in the hub.
 *
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-upload-video
 */
final class TikTokPublisher implements Publisher
{
    private const POLL_INTERVAL_SECONDS = 5;

    private const POLL_TIMEOUT_SECONDS = 600;

    /**
     * TikTok reads the caption as the post title; anything longer is refused.
     */
    private const MAX_TITLE_CHARS = 2200;

    /**
     * A photo post splits the text: a short title and a long description (UTF-16 units).
     */
    private const MAX_PHOTO_TITLE = 90;

    private const MAX_PHOTO_DESCRIPTION = 4000;

    private const MAX_PHOTOS = 35;

    private const MAX_PHOTO_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly TikTokClient $client) {}

    public function supports(Platform $platform): bool
    {
        return $platform === Platform::TikTok;
    }

    public function publish(PostVariant $variant): PublishResult
    {
        $account = $variant->account;

        if ($account === null || blank($account->access_token)) {
            throw new PermanentPublishException('Varijanta nema povezan TikTok račun (ili nedostaje token).', 'no_account');
        }

        if ($account->token_expires_at !== null && $account->token_expires_at->isPast()) {
            throw new PermanentPublishException('TikTok token je istekao; pokreni hub:refresh-tiktok-tokens ili poveži račun ponovno.', 'token_expired');
        }

        $client = $this->client->forVariant($variant);
        $token = (string) $account->access_token;

        $inbox = $variant->setting('delivery') === 'inbox';

        if (in_array($variant->format(), [ContentFormat::Image, ContentFormat::Carousel], true)) {
            return $this->publishPhotos($client, $variant, $token, $inbox);
        }

        if ($inbox) {
            return $this->sendToInbox($client, $variant, $token);
        }

        $asset = $this->video($variant);
        $creator = $client->post('post/publish/creator_info/query/', [], $token, 'tiktok.creator_info');

        $this->assertDuration($asset, $creator);
        $title = $this->title($variant);

        $init = $client->post('post/publish/video/init/', [
            'post_info' => [
                'title' => $title,
                'privacy_level' => $this->privacyLevel($variant, $creator),
                'disable_comment' => (bool) $variant->setting('disable_comment', (bool) ($creator['comment_disabled'] ?? false)),
                'disable_duet' => (bool) $variant->setting('disable_duet', (bool) ($creator['duet_disabled'] ?? false)),
                'disable_stitch' => (bool) $variant->setting('disable_stitch', (bool) ($creator['stitch_disabled'] ?? false)),
                'video_cover_timestamp_ms' => (int) $variant->setting('cover_timestamp_ms', 0),
            ],
            'source_info' => [
                // The hub's media domain must be verified in the TikTok developer portal, otherwise
                // TikTok refuses to fetch the file. See docs/tiktok.md.
                'source' => 'PULL_FROM_URL',
                'video_url' => $asset->publicUrl(),
            ],
        ], $token, 'tiktok.init');

        $publishId = $init['publish_id'] ?? null;

        if (blank($publishId)) {
            throw new TransientPublishException('TikTok nije vratio publish_id: '.json_encode($init), 'tiktok_no_publish_id');
        }

        $status = $this->await($client, (string) $publishId, $token, ['PUBLISH_COMPLETE']);

        return new PublishResult(
            (string) $publishId,
            $this->permalink($creator, $status),
            ['creator' => $creator['creator_username'] ?? null, 'status' => $status],
        );
    }

    /**
     * TikTok counts its limits in UTF-16 units, so an emoji costs two.
     */
    private static function utf16Cut(string $text, int $max): string
    {
        $units = 0;
        $out = '';

        foreach (mb_str_split($text) as $char) {
            $width = mb_ord($char) > 0xFFFF ? 2 : 1;

            if ($units + $width > $max) {
                break;
            }

            $units += $width;
            $out .= $char;
        }

        return $out;
    }

    /**
     * A photo post (one image or a carousel of up to 35). Same two walls as video — privacy the
     * creator allows, media on the verified domain — but TikTok splits the text into a short title
     * and a description, and can lay its own music under the photos (`auto_add_music`).
     *
     * @see https://developers.tiktok.com/doc/content-posting-api-reference-photo-post
     */
    private function publishPhotos(TikTokClient $client, PostVariant $variant, string $token, bool $inbox): PublishResult
    {
        $photos = $this->photos($variant);
        $caption = mb_trim($variant->caption);

        $postInfo = array_filter([
            'title' => self::utf16Cut($this->firstLine($caption), self::MAX_PHOTO_TITLE),
            'description' => self::utf16Cut($caption, self::MAX_PHOTO_DESCRIPTION),
        ], fn (string $value): bool => $value !== '');

        $creator = [];

        if (! $inbox) {
            if ($caption === '') {
                throw new PermanentPublishException('TikTok objava treba tekst.', 'empty_caption');
            }

            $creator = $client->post('post/publish/creator_info/query/', [], $token, 'tiktok.creator_info');

            $postInfo += [
                'privacy_level' => $this->privacyLevel($variant, $creator),
                'disable_comment' => (bool) $variant->setting('disable_comment', (bool) ($creator['comment_disabled'] ?? false)),
                'auto_add_music' => (bool) $variant->setting('auto_add_music', true),
            ];
        }

        $payload = [
            'media_type' => 'PHOTO',
            'post_mode' => $inbox ? 'MEDIA_UPLOAD' : 'DIRECT_POST',
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'photo_images' => $photos->map(fn (MediaAsset $asset): string => $asset->publicUrl())->values()->all(),
                'photo_cover_index' => 0,
            ],
        ];

        // An inbox upload without text has no post_info at all; an empty one would be encoded as a
        // JSON array, which TikTok rejects as the wrong type (as it did for creator_info/query).
        if ($postInfo !== []) {
            $payload['post_info'] = $postInfo;
        }

        $init = $client->post('post/publish/content/init/', $payload, $token, 'tiktok.photo_init');

        $publishId = $init['publish_id'] ?? null;

        if (blank($publishId)) {
            throw new TransientPublishException('TikTok nije vratio publish_id: '.json_encode($init), 'tiktok_no_publish_id');
        }

        if ($inbox) {
            $status = $this->await($client, (string) $publishId, $token, ['SEND_TO_USER_INBOX', 'PUBLISH_COMPLETE']);

            return new PublishResult((string) $publishId, null, ['status' => $status], handedToCreator: true);
        }

        $status = $this->await($client, (string) $publishId, $token, ['PUBLISH_COMPLETE']);

        return new PublishResult(
            (string) $publishId,
            $this->permalink($creator, $status, 'photo'),
            ['creator' => $creator['creator_username'] ?? null, 'status' => $status],
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, MediaAsset>
     */
    private function photos(PostVariant $variant): \Illuminate\Support\Collection
    {
        $media = $variant->media()->get();

        if ($media->isEmpty()) {
            throw new PermanentPublishException('TikTok foto objava treba barem jednu sliku.', 'no_media');
        }

        if ($media->contains(fn (MediaAsset $asset): bool => $asset->isVideo())) {
            throw new PermanentPublishException('TikTok foto objava prima samo slike; priložen je video.', 'not_image');
        }

        if ($media->count() > self::MAX_PHOTOS) {
            throw new PermanentPublishException(sprintf('TikTok foto objava prima najviše %d slika; priloženo je %d.', self::MAX_PHOTOS, $media->count()), 'too_many_images');
        }

        foreach ($media as $index => $asset) {
            if (! in_array($asset->format, ['jpg', 'jpeg', 'webp'], true)) {
                throw new PermanentPublishException(sprintf('Slika %d je %s; TikTok prima JPEG ili WEBP.', $index + 1, $asset->format), 'not_jpeg');
            }

            if ($asset->bytes !== null && $asset->bytes > self::MAX_PHOTO_BYTES) {
                throw new PermanentPublishException(sprintf('Slika %d ima %.1f MB; TikTok prima najviše 20 MB.', $index + 1, $asset->bytes / 1048576), 'image_too_large');
            }

            if (app()->isProduction() && ! str_starts_with($asset->publicUrl(), 'https://')) {
                throw new PermanentPublishException('TikTok dohvaća slike s naše adrese i traži https.', 'media_url_not_https');
            }
        }

        return $media;
    }

    private function firstLine(string $caption): string
    {
        return mb_trim(preg_split('/\R/u', $caption, 2)[0] ?? '');
    }

    /**
     * Upload only: no post_info (the caption, privacy and sound are set in the app), and no
     * creator_info — that belongs to Direct Post and a brand may have granted only `video.upload`.
     */
    private function sendToInbox(TikTokClient $client, PostVariant $variant, string $token): PublishResult
    {
        $asset = $this->video($variant);
        $this->assertDuration($asset, []);

        $init = $client->post('post/publish/inbox/video/init/', [
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'video_url' => $asset->publicUrl(),
            ],
        ], $token, 'tiktok.inbox_init');

        $publishId = $init['publish_id'] ?? null;

        if (blank($publishId)) {
            throw new TransientPublishException('TikTok nije vratio publish_id: '.json_encode($init), 'tiktok_no_publish_id');
        }

        // The creator may post from the app between two polls; PUBLISH_COMPLETE is then the first
        // state we see. Waiting only for SEND_TO_USER_INBOX would time out and upload a second copy.
        $status = $this->await($client, (string) $publishId, $token, ['SEND_TO_USER_INBOX', 'PUBLISH_COMPLETE']);

        return new PublishResult((string) $publishId, null, ['status' => $status], handedToCreator: true);
    }

    /**
     * @param  list<string>  $doneStates
     * @return array<string, mixed>
     */
    private function await(TikTokClient $client, string $publishId, string $token, array $doneStates): array
    {
        $deadline = microtime(true) + self::POLL_TIMEOUT_SECONDS;

        while (true) {
            $status = $client->post('post/publish/status/fetch/', ['publish_id' => $publishId], $token, 'tiktok.status');
            $state = (string) ($status['status'] ?? '');

            if (in_array($state, $doneStates, true)) {
                return $status;
            }

            if ($state === 'FAILED') {
                throw new PermanentPublishException(
                    'TikTok je odbio objavu: '.($status['fail_reason'] ?? json_encode($status)),
                    'tiktok_publish_failed',
                );
            }

            if (microtime(true) >= $deadline) {
                throw new TransientPublishException(
                    sprintf('TikTok obrađuje objavu dulje od %d s (zadnji status: %s).', self::POLL_TIMEOUT_SECONDS, $state ?: 'nepoznat'),
                    'tiktok_timeout',
                );
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
        }
    }

    private function video(PostVariant $variant): MediaAsset
    {
        $media = $variant->media()->get();

        if ($media->count() !== 1) {
            throw new PermanentPublishException('TikTok objava je jedan video; priloženo je '.$media->count().' datoteka.', 'tiktok_needs_one_video');
        }

        $asset = $media->first();

        if (! $asset->isVideo()) {
            throw new PermanentPublishException("TikTok traži MP4; priloženo je {$asset->format}.", 'not_video');
        }

        if (app()->isProduction() && ! str_starts_with($asset->publicUrl(), 'https://')) {
            throw new PermanentPublishException('TikTok dohvaća video s naše adrese i traži https.', 'media_url_not_https');
        }

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $creator
     */
    private function assertDuration(MediaAsset $asset, array $creator): void
    {
        $seconds = $asset->durationSeconds();
        $max = (int) ($creator['max_video_post_duration_sec'] ?? 0);

        if ($seconds !== null && $seconds < 3) {
            throw new PermanentPublishException(sprintf('Video traje %.1f s; TikTok traži barem 3 s.', $seconds), 'bad_video_duration');
        }

        if ($seconds !== null && $max > 0 && $seconds > $max) {
            throw new PermanentPublishException(
                sprintf('Video traje %.1f s, a ovaj račun smije objaviti najviše %d s.', $seconds, $max),
                'video_too_long',
            );
        }
    }

    /**
     * The creator decides what is allowed, and an unaudited app only ever gets SELF_ONLY.
     *
     * @param  array<string, mixed>  $creator
     */
    private function privacyLevel(PostVariant $variant, array $creator): string
    {
        $allowed = array_values(array_filter((array) ($creator['privacy_level_options'] ?? [])));
        $wanted = (string) $variant->setting('privacy_level', config('tiktok.default_privacy_level', 'SELF_ONLY'));

        if ($allowed === []) {
            return $wanted;
        }

        if (in_array($wanted, $allowed, true)) {
            return $wanted;
        }

        $fallback = in_array('SELF_ONLY', $allowed, true) ? 'SELF_ONLY' : $allowed[0];

        Log::warning('hub.tiktok.privacy_level_adjusted', ['wanted' => $wanted, 'allowed' => $allowed, 'used' => $fallback]);

        return $fallback;
    }

    private function title(PostVariant $variant): string
    {
        $title = mb_trim($variant->caption);

        if ($title === '') {
            throw new PermanentPublishException('TikTok objava treba tekst.', 'empty_caption');
        }

        return mb_substr($title, 0, self::MAX_TITLE_CHARS);
    }

    /**
     * @param  array<string, mixed>  $creator
     * @param  array<string, mixed>  $status
     */
    private function permalink(array $creator, array $status, string $kind = 'video'): ?string
    {
        $username = $creator['creator_username'] ?? null;
        $postId = $status['publicaly_available_post_id'][0] ?? null;

        if (blank($username) || blank($postId)) {
            return filled($username) ? "https://www.tiktok.com/@{$username}" : null;
        }

        return "https://www.tiktok.com/@{$username}/{$kind}/{$postId}";
    }
}
