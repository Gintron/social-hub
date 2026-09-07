<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Publishing\Exceptions\PermanentPublishException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Facebook login round-trip: consent dialog, code → short-lived token → long-lived token.
 *
 * The long-lived *user* token lasts ~60 days, but the Page tokens derived from it (see
 * MetaAssetDiscovery) do not expire at all, which is why the hub stores those and not this one.
 */
final class MetaOAuth
{
    /**
     * URL of the consent dialog the admin is sent to.
     */
    public function dialogUrl(string $state): string
    {
        $this->assertConfigured();

        $query = [
            'client_id' => (string) config('meta.app_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
        ];

        // Facebook Login for Business drives permissions from a saved configuration; classic
        // Facebook Login takes an explicit scope list.
        if (filled(config('meta.login_config_id'))) {
            $query['config_id'] = (string) config('meta.login_config_id');
        } else {
            $query['scope'] = implode(',', (array) config('meta.scopes', []));
        }

        return 'https://www.facebook.com/'.config('meta.graph_version').'/dialog/oauth?'.http_build_query($query);
    }

    /**
     * Exchange the callback code for a long-lived user access token.
     *
     * @return array{token: string, expires_at: CarbonImmutable|null}
     */
    public function exchangeCode(string $code): array
    {
        $this->assertConfigured();

        $short = $this->tokenRequest([
            'client_id' => (string) config('meta.app_id'),
            'client_secret' => (string) config('meta.app_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        $long = $this->tokenRequest([
            'grant_type' => 'fb_exchange_token',
            'client_id' => (string) config('meta.app_id'),
            'client_secret' => (string) config('meta.app_secret'),
            'fb_exchange_token' => $short['access_token'],
        ]);

        $expiresIn = (int) ($long['expires_in'] ?? 0);

        return [
            'token' => (string) $long['access_token'],
            'expires_at' => $expiresIn > 0 ? CarbonImmutable::now()->addSeconds($expiresIn) : null,
        ];
    }

    public function redirectUri(): string
    {
        $configured = (string) config('meta.redirect');

        return $configured !== '' ? $configured : url('/meta/callback');
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function tokenRequest(array $query): array
    {
        $url = mb_rtrim((string) config('meta.graph_base'), '/').'/'.config('meta.graph_version').'/oauth/access_token';

        try {
            $response = Http::acceptJson()->timeout((int) config('meta.http_timeout', 30))->get($url, $query);
        } catch (ConnectionException $e) {
            throw new PermanentPublishException("Meta nije dostupna: {$e->getMessage()}", 'meta_unreachable');
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || blank($body['access_token'] ?? null)) {
            $message = is_array($body) ? ($body['error']['message'] ?? $response->body()) : $response->body();

            throw new PermanentPublishException('Razmjena tokena nije uspjela: '.mb_substr((string) $message, 0, 300), 'oauth_exchange_failed');
        }

        return $body;
    }

    private function assertConfigured(): void
    {
        foreach (['app_id', 'app_secret'] as $key) {
            if (blank(config("meta.{$key}"))) {
                throw new RuntimeException('META_'.mb_strtoupper($key).' nije postavljen u .env.');
            }
        }
    }
}
