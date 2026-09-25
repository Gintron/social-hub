<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Models\PostVariant;
use App\Models\PublishLog;
use App\Publishing\Exceptions\TransientPublishException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin Graph API wrapper: pinned version, appsecret_proof on every call, redacted request/response log
 * per variant, and error envelopes mapped to retry semantics.
 */
final class GraphClient
{
    private ?PostVariant $variant = null;

    public function __construct(private readonly GraphErrorMapper $errors) {}

    /**
     * Tokens out of anything Graph sent or was sent, before it is stored: the fields themselves, and
     * the query strings Graph echoes back — every `paging.next` link carries the Page token.
     *
     * @template T of array<array-key, mixed>
     *
     * @param  T  $data
     * @return T
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['access_token', 'appsecret_proof'], true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_string($value)) {
                $data[$key] = preg_replace('/\b(access_token|appsecret_proof)=[^&\s"]+/', '$1=[redacted]', $value) ?? $value;
            }
        }

        return $data;
    }

    /**
     * Log every request of this client against a variant (publish_logs).
     */
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
    public function get(string $path, array $query, string $token, string $event = 'graph.get'): array
    {
        return $this->send('GET', $path, $query, $token, $event);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $path, array $data, string $token, string $event = 'graph.post'): array
    {
        return $this->send('POST', $path, $data, $token, $event);
    }

    /**
     * A raw POST to an upload host Meta hands back (rupload.facebook.com).
     *
     * Not a Graph call: the token travels in an Authorization header rather than as a form field,
     * there is no appsecret_proof, and the body is empty because the file is named by a header.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function upload(string $url, array $headers, string $token, string $event): array
    {
        try {
            $response = $this->http()
                ->withHeaders([...$headers, 'Authorization' => 'OAuth '.$token])
                ->timeout((int) config('meta.upload_timeout', 300))
                ->post($url);
        } catch (ConnectionException $e) {
            $this->log($event, null, 'POST', $url, $headers, ['exception' => $e->getMessage()]);

            throw new TransientPublishException("Upload host nije dostupan: {$e->getMessage()}", 'upload_unreachable');
        }

        $this->log($event, $response, 'POST', $url, $headers, $response->json() ?? ['body' => mb_substr($response->body(), 0, 500)]);

        if (! $response->successful()) {
            throw $this->errors->map($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function url(string $path): string
    {
        return mb_rtrim((string) config('meta.graph_base'), '/').'/'.config('meta.graph_version').'/'.mb_ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $params, string $token, string $event): array
    {
        $params['access_token'] = $token;

        $secret = (string) config('meta.app_secret');
        if ($secret !== '') {
            $params['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
        }

        $url = $this->url($path);

        try {
            $response = $method === 'GET'
                ? $this->http()->get($url, $params)
                : $this->http()->asForm()->post($url, $params);
        } catch (ConnectionException $e) {
            $this->log($event, null, $method, $url, $params, ['exception' => $e->getMessage()]);

            throw new TransientPublishException("Graph API unreachable: {$e->getMessage()}", 'graph_unreachable');
        }

        $this->log($event, $response, $method, $url, $params, $response->json() ?? ['body' => mb_substr($response->body(), 0, 1000)]);

        if (! $response->successful()) {
            throw $this->errors->map($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout((int) config('meta.http_timeout', 30));
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $responseBody
     */
    private function log(string $event, ?Response $response, string $method, string $url, array $params, ?array $responseBody): void
    {
        if ($this->variant === null) {
            return;
        }

        PublishLog::query()->create([
            'post_variant_id' => $this->variant->id,
            'event' => $event,
            'http_status' => $response?->status(),
            'request' => ['method' => $method, 'url' => $url, 'params' => self::redact($params)],
            'response' => $responseBody === null ? null : self::redact($responseBody),
        ]);
    }
}
