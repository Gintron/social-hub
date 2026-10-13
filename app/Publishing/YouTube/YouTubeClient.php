<?php

declare(strict_types=1);

namespace App\Publishing\YouTube;

use App\Models\PostVariant;
use App\Models\PublishLog;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Exceptions\RateLimitedException;
use App\Publishing\Exceptions\TokenInvalidException;
use App\Publishing\Exceptions\TransientPublishException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The YouTube Data API v3: JSON calls, and the resumable upload that puts a video on a channel.
 *
 * A failure comes back as a non-2xx status with `error.errors[0].reason` in the body. The reason, not
 * only the status, decides whether the hub waits (an allowance ran out), asks for a new connection (no
 * permission, a revoked grant) or gives up (metadata YouTube refuses).
 *
 * @see https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol
 */
final class YouTubeClient
{
    /**
     * Reasons that mean an allowance ran out. They reset at midnight Pacific time.
     */
    private const QUOTA_REASONS = [
        'quotaExceeded', 'dailyLimitExceeded', 'dailyLimitExceededUnreg', 'rateLimitExceeded',
        'userRateLimitExceeded', 'uploadLimitExceeded',
    ];

    private ?PostVariant $variant = null;

    public function forVariant(?PostVariant $variant): self
    {
        $clone = clone $this;
        $clone->variant = $variant;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query, string $token, string $event): array
    {
        $url = $this->url((string) config('youtube.api_base'), $path);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('youtube.http_timeout', 30))
                ->get($url, $query);
        } catch (ConnectionException $e) {
            $this->log($event, null, 'GET', $url, $query, ['exception' => $e->getMessage()]);

            throw new TransientPublishException("YouTube nije dostupan: {$e->getMessage()}", 'youtube_unreachable');
        }

        $this->log($event, $response, 'GET', $url, $query, $this->body($response));

        if (! $response->successful()) {
            throw $this->mapError($response);
        }

        return (array) $response->json();
    }

    /**
     * Opens a resumable upload and returns the session URL the file goes to. Nothing but the metadata is sent.
     *
     * @param  array<string, mixed>  $video  The `video` resource: its snippet and status.
     */
    public function startUpload(array $video, int $bytes, string $token): string
    {
        $url = (string) config('youtube.upload_url').'?'.http_build_query(['uploadType' => 'resumable', 'part' => 'snippet,status']);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-Upload-Content-Length' => (string) $bytes,
                    'X-Upload-Content-Type' => 'video/mp4',
                ])
                ->timeout((int) config('youtube.http_timeout', 30))
                ->post($url, $video);
        } catch (ConnectionException $e) {
            $this->log('youtube.upload_start', null, 'POST', $this->plain($url), $video, ['exception' => $e->getMessage()]);

            throw new TransientPublishException("YouTube nije dostupan: {$e->getMessage()}", 'youtube_unreachable');
        }

        $this->log('youtube.upload_start', $response, 'POST', $this->plain($url), $video, $this->body($response));

        if (! $response->successful()) {
            throw $this->mapError($response);
        }

        $session = (string) $response->header('Location');

        if ($session === '') {
            throw new TransientPublishException('YouTube nije vratio adresu za upload.', 'youtube_no_session');
        }

        return $session;
    }

    /**
     * Sends the whole file to the session URL. Google answers 201 with the video resource, id included.
     *
     * @return array<string, mixed>
     */
    public function sendVideo(string $sessionUrl, string $absolutePath, int $bytes, string $token): array
    {
        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new PermanentPublishException('Video nije na disku; renderiraj ga ponovno.', 'media_missing');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders(['Content-Length' => (string) $bytes])
                ->withBody($stream, 'video/mp4')
                ->timeout((int) config('youtube.upload_timeout', 100))
                ->put($sessionUrl);
        } catch (ConnectionException $e) {
            $this->log('youtube.upload', null, 'PUT', $this->plain($sessionUrl), ['bytes' => $bytes], ['exception' => $e->getMessage()]);

            // Whatever arrived went with the connection; the retry opens a new session.
            throw new TransientPublishException("YouTube je prekinuo upload: {$e->getMessage()}", 'youtube_upload_interrupted');
        } finally {
            fclose($stream);
        }

        $this->log('youtube.upload', $response, 'PUT', $this->plain($sessionUrl), ['bytes' => $bytes], $this->body($response));

        if ($response->status() === 404) {
            throw new TransientPublishException('YouTube je zatvorio sesiju uploada; sljedeći pokušaj otvara novu.', 'youtube_session_expired');
        }

        if ($response->status() === 308) {
            throw new TransientPublishException('YouTube je primio samo dio videa; sljedeći pokušaj šalje cijeli.', 'youtube_upload_incomplete');
        }

        if (! $response->successful()) {
            throw $this->mapError($response);
        }

        $video = (array) $response->json();

        // The file may be on the channel already; retrying would upload a second copy, so a person checks first.
        if (blank($video['id'] ?? null)) {
            throw new PermanentPublishException('YouTube je primio video, ali nije vratio njegov id; provjeri kanal prije ponovne objave.', 'youtube_no_video_id');
        }

        return $video;
    }

    /**
     * The quota resets at midnight Pacific time; the job waits until then, plus a minute to spare.
     */
    private static function secondsUntilQuotaReset(): int
    {
        $midnight = CarbonImmutable::now('America/Los_Angeles')->addDay()->startOfDay();

        return max(60, $midnight->getTimestamp() - CarbonImmutable::now()->getTimestamp() + 60);
    }

    private function mapError(Response $response): PublishException
    {
        $status = $response->status();
        $reason = (string) data_get($response->json(), 'error.errors.0.reason', '');
        $message = (string) data_get($response->json(), 'error.message', $response->body());
        $summary = "YouTube {$status}".($reason !== '' ? " ({$reason})" : '').": {$message}";

        return match (true) {
            $status === 401 => new TokenInvalidException($summary),
            $status === 403 && in_array($reason, self::QUOTA_REASONS, true) => new RateLimitedException($summary, self::secondsUntilQuotaReset()),
            $status === 403 && $reason === 'insufficientPermissions' => new TokenInvalidException("{$summary} Odobrenje nema dopuštenje za tu radnju; poveži kanal ponovno."),
            $status === 403 && $reason === 'youtubeSignupRequired' => new PermanentPublishException("{$summary} Google račun nema YouTube kanal.", 'youtube_no_channel'),
            $status === 429 => new RateLimitedException($summary, 900),
            $status >= 500 => new TransientPublishException($summary, 'youtube_transient'),
            default => new PermanentPublishException($summary, 'youtube_error'),
        };
    }

    private function url(string $base, string $path): string
    {
        return mb_rtrim($base, '/').'/'.mb_ltrim($path, '/');
    }

    /**
     * A session URL carries the upload id in its query; the log keeps only the part that names the call.
     */
    private function plain(string $url): string
    {
        return explode('?', $url, 2)[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['body' => mb_substr($response->body(), 0, 500)];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $body
     */
    private function log(string $event, ?Response $response, string $method, string $url, array $payload, ?array $body): void
    {
        if ($this->variant === null) {
            return;
        }

        PublishLog::query()->create([
            'post_variant_id' => $this->variant->id,
            'event' => $event,
            'http_status' => $response?->status(),
            'request' => ['method' => $method, 'url' => $url, 'params' => $payload],
            'response' => $body,
        ]);
    }
}
