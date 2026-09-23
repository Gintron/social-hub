<?php

declare(strict_types=1);

namespace App\Publishing\TikTok;

use App\Publishing\Exceptions\PermanentPublishException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TikTok login and token rotation.
 *
 * Unlike Meta's page tokens, nothing here is permanent: an access token lasts a day and a refresh
 * token about a year, and both are replaced on every refresh.
 */
final class TikTokOAuth implements TikTokTokens
{
    public function authorizeUrl(string $state): string
    {
        $this->assertConfigured();

        return config('tiktok.authorize_url').'?'.http_build_query([
            'client_key' => (string) config('tiktok.client_key'),
            'scope' => implode(',', (array) config('tiktok.scopes', [])),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, open_id: string, expires_at: CarbonImmutable, refresh_expires_at: CarbonImmutable|null, scope: string}
     */
    public function exchangeCode(string $code): array
    {
        return $this->token([
            'client_key' => (string) config('tiktok.client_key'),
            'client_secret' => (string) config('tiktok.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, open_id: string, expires_at: CarbonImmutable, refresh_expires_at: CarbonImmutable|null, scope: string}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token([
            'client_key' => (string) config('tiktok.client_key'),
            'client_secret' => (string) config('tiktok.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function redirectUri(): string
    {
        $configured = (string) config('tiktok.redirect');

        return $configured !== '' ? $configured : url('/tiktok/callback');
    }

    /**
     * @param  array<string, string>  $payload
     * @return array{access_token: string, refresh_token: string, open_id: string, expires_at: CarbonImmutable, refresh_expires_at: CarbonImmutable|null, scope: string}
     */
    private function token(array $payload): array
    {
        $this->assertConfigured();

        $url = mb_rtrim((string) config('tiktok.api_base'), '/').'/oauth/token/';

        try {
            // This endpoint takes form encoding, not JSON, unlike the rest of the API.
            $response = Http::asForm()->acceptJson()->timeout((int) config('tiktok.http_timeout', 30))->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new PermanentPublishException("TikTok nije dostupan: {$e->getMessage()}", 'tiktok_unreachable');
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || blank($body['access_token'] ?? null)) {
            $message = is_array($body) ? ($body['error_description'] ?? $body['message'] ?? $response->body()) : $response->body();

            throw new PermanentPublishException('TikTok nije izdao token: '.mb_substr((string) $message, 0, 300), 'tiktok_oauth_failed');
        }

        $refreshExpires = (int) ($body['refresh_expires_in'] ?? 0);

        return [
            'access_token' => (string) $body['access_token'],
            'refresh_token' => (string) ($body['refresh_token'] ?? ''),
            'open_id' => (string) ($body['open_id'] ?? ''),
            'expires_at' => CarbonImmutable::now()->addSeconds((int) ($body['expires_in'] ?? 86400)),
            'refresh_expires_at' => $refreshExpires > 0 ? CarbonImmutable::now()->addSeconds($refreshExpires) : null,
            'scope' => (string) ($body['scope'] ?? ''),
        ];
    }

    private function assertConfigured(): void
    {
        foreach (['client_key', 'client_secret'] as $key) {
            if (blank(config("tiktok.{$key}"))) {
                throw new RuntimeException('TIKTOK_'.mb_strtoupper($key).' nije postavljen u .env.');
            }
        }
    }
}
