<?php

declare(strict_types=1);

namespace App\Publishing\TikTok\Business;

use App\Enums\Platform;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Publishing\Contracts\Publisher;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\TransientPublishException;
use App\Publishing\PublishResult;

/**
 * Publishing through TikTok API for Business (Accounts API).
 *
 * Simpler than the developer track: one call publishes a public post, so there is no container to
 * poll and no privacy negotiation — the SELF_ONLY wall does not exist here. What stays is asking
 * the account what it allows (`/business/video/settings/`) before posting within those limits.
 *
 * Variant settings: `disable_comment`, `disable_duet`, `disable_stitch`, `cover_timestamp_ms`,
 * `delivery` (`direct` | `inbox`), `branded_content`.
 *
 * @see https://business-api.tiktok.com/portal/docs/publish-a-public-video-post-to-an-owned-account/v1.3
 */
final class TikTokBusinessPublisher implements Publisher
{
    /**
     * TikTok counts captions in UTF-16 units, so an emoji costs two.
     */
    private const MAX_CAPTION = 2200;

    private const MIN_SECONDS = 3;

    private const MAX_SECONDS = 600;

    public function __construct(private readonly TikTokBusinessClient $client) {}

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

        $businessId = (string) ($account->meta['open_id'] ?? $account->external_id);

        if ($businessId === '') {
            throw new PermanentPublishException('TikTok račun nema open_id; poveži ga ponovno.', 'no_business_id');
        }

        $client = $this->client->forVariant($variant);
        $token = (string) $account->access_token;
        $inbox = $variant->setting('delivery') === 'inbox';

        $settings = $client->get('business/video/settings/', ['business_id' => $businessId], $token, 'tiktok.settings');

        $asset = $this->video($variant);
        $this->assertDuration($asset, $settings);

        $data = $client->post('business/video/publish/', [
            'business_id' => $businessId,
            'video_url' => $asset->publicUrl(),
            'post_info' => $inbox ? ['upload_to_draft' => true] : $this->postInfo($variant, $settings),
        ], $token, $inbox ? 'tiktok.draft' : 'tiktok.publish');

        $shareId = $data['share_id'] ?? null;

        if (blank($shareId)) {
            throw new TransientPublishException('TikTok nije vratio share_id: '.json_encode($data), 'tiktok_no_share_id');
        }

        // The permalink is not in this response — it arrives in the publish webhook, or through the
        // status endpoint. Until the app is live and those shapes are confirmed, the post is public
        // but the hub has no link to show for it yet.
        return new PublishResult((string) $shareId, null, ['share_id' => $shareId], handedToCreator: $inbox);
    }

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
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function postInfo(PostVariant $variant, array $settings): array
    {
        $caption = mb_trim($variant->caption);

        if ($caption === '') {
            throw new PermanentPublishException('TikTok objava treba tekst.', 'empty_caption');
        }

        $branded = (bool) $variant->setting('branded_content', false);

        return [
            'caption' => self::utf16Cut($caption, self::MAX_CAPTION),
            // Required pair. The hub posts a brand's own offers, so this is Brand Organic unless the
            // variant says it is a paid partnership; setting both would silently drop brand organic.
            'is_brand_organic' => ! $branded && (bool) config('tiktok.business.brand_organic', true),
            'is_branded_content' => $branded,
            'disable_comment' => (bool) $variant->setting('disable_comment', (bool) ($settings['comment_disabled'] ?? false)),
            'disable_duet' => (bool) $variant->setting('disable_duet', (bool) ($settings['duet_disabled'] ?? false)),
            'disable_stitch' => (bool) $variant->setting('disable_stitch', (bool) ($settings['stitch_disabled'] ?? false)),
            'thumbnail_offset' => (int) $variant->setting('cover_timestamp_ms', 0),
        ];
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

        // The media domain must be a verified URL property, otherwise TikTok refuses to fetch it.
        if (app()->isProduction() && ! str_starts_with($asset->publicUrl(), 'https://')) {
            throw new PermanentPublishException('TikTok dohvaća video s naše adrese i traži https.', 'media_url_not_https');
        }

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function assertDuration(MediaAsset $asset, array $settings): void
    {
        $seconds = $asset->durationSeconds();

        if ($seconds === null) {
            return;
        }

        $max = (int) ($settings['max_video_post_duration_sec'] ?? 0) ?: self::MAX_SECONDS;

        if ($seconds < self::MIN_SECONDS) {
            throw new PermanentPublishException(sprintf('Video traje %.1f s; TikTok traži barem %d s.', $seconds, self::MIN_SECONDS), 'bad_video_duration');
        }

        if ($seconds > $max) {
            throw new PermanentPublishException(
                sprintf('Video traje %.1f s, a ovaj račun smije objaviti najviše %d s.', $seconds, $max),
                'video_too_long',
            );
        }
    }
}
