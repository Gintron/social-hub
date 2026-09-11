<?php

declare(strict_types=1);

namespace App\Publishing\TikTok;

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
 * TikTok's Content Posting API.
 *
 * Two things differ from Graph and both bite once: a successful response still carries an `error`
 * object (with `code: "ok"`), and the token goes in an Authorization header rather than the body.
 *
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
 */
final class TikTokClient
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
        $url = mb_rtrim((string) config('tiktok.api_base'), '/').'/'.mb_ltrim($path, '/');

        // PHP's empty array and empty object both start life as `[]`; json_encode can't tell them
        // apart and always picks array. TikTok's endpoints (creator_info/query in particular) want
        // an object body even when there's nothing to send, and reject `[]` as the wrong type.
        $requestBody = $payload === [] ? (object) [] : $payload;

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('tiktok.http_timeout', 30))
                ->post($url, $requestBody);
        } catch (ConnectionException $e) {
            $this->log($event, null, $url, $payload, ['exception' => $e->getMessage()]);

            throw new TransientPublishException("TikTok nije dostupan: {$e->getMessage()}", 'tiktok_unreachable');
        }

        $body = $response->json();
        $this->log($event, $response, $url, $payload, is_array($body) ? $body : ['body' => mb_substr($response->body(), 0, 500)]);

        $error = is_array($body) ? ($body['error'] ?? []) : [];
        $code = (string) ($error['code'] ?? '');

        if (! $response->successful() || ($code !== '' && $code !== 'ok')) {
            throw $this->mapError($response, $code, (string) ($error['message'] ?? $response->body()));
        }

        return is_array($body) ? (array) ($body['data'] ?? []) : [];
    }

    private function mapError(Response $response, string $code, string $message): PublishException
    {
        $summary = "TikTok {$response->status()} ({$code}): {$message}";

        return match (true) {
            in_array($code, ['access_token_invalid', 'scope_not_authorized', 'scope_permission_missed'], true) || $response->status() === 401 => new TokenInvalidException($summary),
            $code === 'rate_limit_exceeded' || $response->status() === 429 => new RateLimitedException($summary, 900),
            // The creator has to accept the app's terms in the TikTok app before it may post.
            $code === 'unaudited_client_can_only_post_to_private_accounts' => new PermanentPublishException($summary.' — aplikacija nije prošla TikTok audit, pa objavljuje samo privatno i samo na privatne račune.', 'tiktok_unaudited'),
            $code === 'spam_risk_too_many_posts' || $code === 'spam_risk_user_banned_from_posting' => new RateLimitedException($summary, 3600),
            $response->serverError() => new TransientPublishException($summary, 'tiktok_transient'),
            default => new PermanentPublishException($summary, 'tiktok_error'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $body
     */
    private function log(string $event, ?Response $response, string $url, array $payload, ?array $body): void
    {
        if ($this->variant === null) {
            return;
        }

        PublishLog::query()->create([
            'post_variant_id' => $this->variant->id,
            'event' => $event,
            'http_status' => $response?->status(),
            'request' => ['method' => 'POST', 'url' => $url, 'params' => $payload],
            'response' => $body,
        ]);
    }
}
