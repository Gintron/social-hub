<?php

declare(strict_types=1);

namespace App\Publishing\YouTube;

use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\TokenInvalidException;
use App\Publishing\Exceptions\TransientPublishException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google OAuth 2.0 for the YouTube Data API, the web server flow.
 *
 * `access_type=offline` with `prompt=consent` makes Google hand back a refresh token on every connect;
 * without `prompt=consent` it comes back only the first time a person approves the app. The access
 * token lasts an hour and YouTubeTokens trades the refresh token for a new one when it is used. Google
 * does not rotate the refresh token, so a renewal that carries none keeps the stored one.
 *
 * @see https://developers.google.com/identity/protocols/oauth2/web-server
 */
final class YouTubeOAuth
{
    /**
     * `state` is the CSRF check the callback verifies; the redirect URI must match the Google client exactly.
     */
    public function authorizeUrl(string $state): string
    {
        $this->assertConfigured();

        return config('youtube.authorize_url').'?'.http_build_query([
            'client_id' => (string) config('youtube.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', (array) config('youtube.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_at: CarbonImmutable, scope: string}
     */
    public function exchangeCode(string $code): array
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_at: CarbonImmutable, scope: string}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function redirectUri(): string
    {
        $configured = (string) config('youtube.redirect');

        return $configured !== '' ? $configured : url('/youtube/callback');
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, refresh_token: string|null, expires_at: CarbonImmutable, scope: string}
     */
    private function token(array $form): array
    {
        $this->assertConfigured();

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('youtube.http_timeout', 30))
                ->post((string) config('youtube.token_url'), [
                    'client_id' => (string) config('youtube.client_id'),
                    'client_secret' => (string) config('youtube.client_secret'),
                    ...$form,
                ]);
        } catch (ConnectionException $e) {
            throw new TransientPublishException("Google OAuth nije dostupan: {$e->getMessage()}", 'youtube_unreachable');
        }

        $body = (array) $response->json();

        if (! $response->successful()) {
            $error = (string) ($body['error'] ?? 'unknown');
            $description = (string) ($body['error_description'] ?? $response->body());

            // An expired, revoked or already-used grant: only a new consent helps.
            if ($error === 'invalid_grant') {
                throw new TokenInvalidException("Google je odbio odobrenje ({$description}); poveži YouTube kanal ponovno.");
            }

            throw $response->serverError()
                ? new TransientPublishException("Google OAuth je nedostupan: {$error}", 'youtube_oauth_unavailable')
                : new PermanentPublishException("Google OAuth je odbio zahtjev: {$error} ({$description}).", 'youtube_oauth_failed');
        }

        if (blank($body['access_token'] ?? null)) {
            throw new PermanentPublishException('Google nije izdao access token.', 'youtube_oauth_failed');
        }

        return [
            'access_token' => (string) $body['access_token'],
            'refresh_token' => filled($body['refresh_token'] ?? null) ? (string) $body['refresh_token'] : null,
            'expires_at' => CarbonImmutable::now()->addSeconds((int) ($body['expires_in'] ?? 3600)),
            'scope' => (string) ($body['scope'] ?? ''),
        ];
    }

    private function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret'] as $key) {
            if (blank(config("youtube.{$key}"))) {
                throw new RuntimeException('YOUTUBE_'.mb_strtoupper($key).' nije postavljen u .env.');
            }
        }
    }
}
