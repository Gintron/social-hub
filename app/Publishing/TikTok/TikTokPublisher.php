<?php

declare(strict_types=1);

namespace App\Publishing\TikTok;

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
 * `cover_timestamp_ms`.
 *
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
 */
final class TikTokPublisher implements Publisher
{
    private const POLL_INTERVAL_SECONDS = 5;

    private const POLL_TIMEOUT_SECONDS = 600;

    /**
     * TikTok reads the caption as the post title; anything longer is refused.
     */
    private const MAX_TITLE_CHARS = 2200;

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

        $status = $this->await($client, (string) $publishId, $token);

        return new PublishResult(
            (string) $publishId,
            $this->permalink($creator, $status),
            ['creator' => $creator['creator_username'] ?? null, 'status' => $status],
        );
    }

    /**
     * @param  array<string, mixed>  $creator
     * @return array<string, mixed>
     */
    private function await(TikTokClient $client, string $publishId, string $token): array
    {
        $deadline = microtime(true) + self::POLL_TIMEOUT_SECONDS;

        while (true) {
            $status = $client->post('post/publish/status/fetch/', ['publish_id' => $publishId], $token, 'tiktok.status');
            $state = (string) ($status['status'] ?? '');

            if ($state === 'PUBLISH_COMPLETE') {
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
    private function permalink(array $creator, array $status): ?string
    {
        $username = $creator['creator_username'] ?? null;
        $postId = $status['publicaly_available_post_id'][0] ?? null;

        if (blank($username) || blank($postId)) {
            return filled($username) ? "https://www.tiktok.com/@{$username}" : null;
        }

        return "https://www.tiktok.com/@{$username}/video/{$postId}";
    }
}
