<?php

declare(strict_types=1);

namespace App\Publishing\YouTube;

use App\Enums\Platform;
use App\Models\PostVariant;
use App\Publishing\Contracts\Publisher;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\PublishResult;
use Illuminate\Support\Facades\Log;

/**
 * Uploads a variant's vertical video to a YouTube channel as a Short.
 *
 * One resumable upload per attempt: open the session with the title, description and privacy, send the
 * file, read back the video id. PublishVariantJob keeps that id, so a finished upload is never repeated;
 * an attempt that failed opens a new session when it is retried.
 *
 * @see https://developers.google.com/youtube/v3/docs/videos/insert
 */
final class YouTubePublisher implements Publisher
{
    public function __construct(
        private readonly YouTubeClient $client,
        private readonly YouTubeTokens $tokens,
        private readonly YouTubePreflight $preflight,
    ) {}

    public function supports(Platform $platform): bool
    {
        return $platform === Platform::YouTube;
    }

    public function publish(PostVariant $variant): PublishResult
    {
        $account = $variant->account;

        if ($account === null || blank($account->refresh_token)) {
            throw new PermanentPublishException('Varijanta nema povezan YouTube kanal (ili nedostaje odobrenje).', 'no_account');
        }

        $asset = $this->preflight->assert($variant);
        [$title, $description] = YouTubePreflight::split((string) $variant->caption);
        $privacy = (string) $variant->setting('privacy', config('youtube.privacy', 'public'));

        $path = $asset->absolutePath();
        $bytes = filesize($path);

        if ($bytes === false) {
            throw new PermanentPublishException('Video nije na disku; renderiraj ga ponovno.', 'media_missing');
        }

        $client = $this->client->forVariant($variant);
        $token = $this->tokens->accessTokenFor($account);

        $session = $client->startUpload([
            'snippet' => [
                'title' => $title,
                'description' => $description,
                'categoryId' => (string) config('youtube.category_id', '22'),
            ],
            'status' => [
                'privacyStatus' => $privacy,
                'selfDeclaredMadeForKids' => false,
            ],
        ], $bytes, $token);

        $video = $client->sendVideo($session, $path, $bytes, $token);

        $id = (string) $video['id'];
        $applied = (string) data_get($video, 'status.privacyStatus', '');

        if ($applied !== '' && $applied !== $privacy) {
            // Google makes uploads from an unaudited project private without an error. The post still counts
            // as published; the log says what was asked for and what was kept.
            Log::warning('hub.youtube.privacy_adjusted', ['variant' => $variant->id, 'requested' => $privacy, 'applied' => $applied]);
        }

        return new PublishResult($id, "https://www.youtube.com/shorts/{$id}", [
            'video_id' => $id,
            'requested_privacy' => $privacy,
            'privacy_status' => $applied !== '' ? $applied : null,
        ]);
    }
}
