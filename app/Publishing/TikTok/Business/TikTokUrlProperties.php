<?php

declare(strict_types=1);

namespace App\Publishing\TikTok\Business;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ownership of the URLs TikTok downloads videos from.
 *
 * `/business/video/publish/` refuses any `video_url` outside a verified property. Verification is
 * per developer app, not per connected account: these calls carry `app_id` and `secret` and no
 * access token, so it can be done before a single brand is connected.
 *
 * @see https://business-api.tiktok.com/portal/docs/manage-url-properties/v1.3
 */
final class TikTokUrlProperties
{
    public const TYPE_DOMAIN = 1;

    public const TYPE_URL_PREFIX = 2;

    public const STATUS_PENDING = 0;

    public const STATUS_VERIFIED = 1;

    public const STATUS_FAILED = 2;

    /**
     * @return list<array{url: string, property_type: int, property_status: int, signature: string, file_name: string}>
     */
    public function list(): array
    {
        $data = $this->call('GET', 'business/property/list/', []);

        return array_values(array_map($this->normalize(...), (array) ($data['url_property_info_list'] ?? [])));
    }

    /**
     * @return array{url: string, property_type: int, property_status: int, signature: string, file_name: string}
     */
    public function add(string $url, int $type = self::TYPE_URL_PREFIX): array
    {
        $data = $this->call('POST', 'business/property/add/', [
            'url_property_meta' => ['url' => $url, 'property_type' => $type],
        ]);

        return $this->normalize((array) ($data['url_property_info'] ?? []));
    }

    /**
     * @return array{url: string, property_type: int, property_status: int, signature: string, file_name: string}
     */
    public function verify(string $url, int $type = self::TYPE_URL_PREFIX): array
    {
        $data = $this->call('POST', 'business/property/verify/', [
            'url_property_meta' => ['url' => $url, 'property_type' => $type],
        ]);

        return $this->normalize((array) ($data['url_property_info'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array{url: string, property_type: int, property_status: int, signature: string, file_name: string}
     */
    private function normalize(array $info): array
    {
        return [
            'url' => (string) ($info['url'] ?? ''),
            'property_type' => (int) ($info['property_type'] ?? 0),
            'property_status' => (int) ($info['property_status'] ?? self::STATUS_PENDING),
            'signature' => (string) ($info['signature'] ?? ''),
            'file_name' => (string) ($info['file_name'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $payload): array
    {
        $appId = (string) config('tiktok.business.app_id');
        $secret = (string) config('tiktok.business.app_secret');

        if ($appId === '' || $secret === '') {
            throw new RuntimeException('TIKTOK_BUSINESS_APP_ID i TIKTOK_BUSINESS_APP_SECRET moraju biti postavljeni u .env.');
        }

        $url = mb_rtrim((string) config('tiktok.business.api_base'), '/').'/'.mb_ltrim($path, '/');
        $payload = ['app_id' => $appId, 'secret' => $secret, ...$payload];

        try {
            $request = Http::acceptJson()->timeout((int) config('tiktok.http_timeout', 30));
            $response = $method === 'GET' ? $request->get($url, $payload) : $request->asJson()->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException("TikTok nije dostupan: {$e->getMessage()}", previous: $e);
        }

        return $this->data($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        $body = $response->json();

        // Same envelope as every Business endpoint: failure is HTTP 200 with a non-zero `code`.
        if (! is_array($body) || (int) ($body['code'] ?? -1) !== 0) {
            $message = is_array($body) ? (string) ($body['message'] ?? $response->body()) : $response->body();

            throw new RuntimeException("TikTok {$response->status()}: ".mb_substr($message, 0, 300));
        }

        return (array) ($body['data'] ?? []);
    }
}
