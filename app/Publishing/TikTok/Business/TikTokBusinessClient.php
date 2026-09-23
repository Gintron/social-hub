<?php

declare(strict_types=1);

namespace App\Publishing\TikTok\Business;

use App\Models\PostVariant;
use App\Models\PublishLog;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Exceptions\RateLimitedException;
use App\Publishing\Exceptions\TokenInvalidException;
use App\Publishing\Exceptions\TransientPublishException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * TikTok API for Business.
 *
 * Two differences from the developer track's client, both silent if missed: the token travels in an
 * `Access-Token` header rather than as a Bearer token, and a failure arrives as HTTP 200 with a
 * non-zero `code` in the envelope.
 *
 * @see https://business-api.tiktok.com/portal/docs
 */
final class TikTokBusinessClient
{
    private ?PostVariant $variant = null;

    public function forVariant(?PostVariant $variant): self
    {
        $clone = clone $this;
        $clone->variant = $variant;

        return $clone;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The `data` object.
     */
    public function post(string $path, array $payload, string $token, string $event): array
    {
        return $this->send('POST', $path, $payload, $token, $event);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> The `data` object.
     */
    public function get(string $path, array $query, string $token, string $event): array
    {
        return $this->send('GET', $path, $query, $token, $event);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload, string $token, string $event): array
    {
        $url = mb_rtrim((string) config('tiktok.business.api_base'), '/').'/'.mb_ltrim($path, '/');

        try {
            $request = Http::withHeaders(['Access-Token' => $token])
                ->acceptJson()
                ->timeout((int) config('tiktok.http_timeout', 30));

            $response = $method === 'GET'
                ? $request->get($url, $payload)
                : $request->asJson()->post($url, $payload === [] ? (object) [] : $payload);
        } catch (ConnectionException $e) {
            $this->log($event, null, $method, $url, $payload, ['exception' => $e->getMessage()]);

            throw new TransientPublishException("TikTok nije dostupan: {$e->getMessage()}", 'tiktok_unreachable');
        }

        $body = $response->json();
        $this->log($event, $response, $method, $url, $payload, is_array($body) ? $body : ['body' => mb_substr($response->body(), 0, 500)]);

        $code = is_array($body) ? (int) ($body['code'] ?? -1) : -1;

        if (! $response->successful() || $code !== 0) {
            $message = is_array($body) ? (string) ($body['message'] ?? $response->body()) : $response->body();

            throw $this->mapError($response, $code, $message);
        }

        return (array) ($body['data'] ?? []);
    }

    /**
     * The full table lives in the API reference appendix; only the families that change what the hub
     * should do are separated here, and anything unrecognised stays permanent so a broken post fails
     * loudly instead of retrying forever.
     */
    private function mapError(Response $response, int $code, string $message): PublishException
    {
        $summary = "TikTok {$response->status()} ({$code}): {$message}";

        return match (true) {
            $response->status() === 401 || $response->status() === 403 || ($code >= 40100 && $code < 40200) => new TokenInvalidException($summary),
            $response->status() === 429 => new RateLimitedException($summary, 900),
            $response->serverError() || $code >= 50000 => new TransientPublishException($summary, 'tiktok_transient'),
            default => new PermanentPublishException($summary, 'tiktok_error'),
        };
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
