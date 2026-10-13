<?php

declare(strict_types=1);

namespace App\Publishing\YouTube;

use App\Models\SocialAccount;
use App\Publishing\Exceptions\TokenInvalidException;

/**
 * The access token a YouTube call is made with. Google's lasts an hour, so it is traded for a fresh one
 * when it is close to expiry, at the moment it is used rather than on a schedule: an upload that starts
 * with a fresh token is not cut off half-way by an old one.
 */
final class YouTubeTokens
{
    /**
     * Renewed this long before expiry, so a call that starts now does not run into it.
     */
    private const RENEW_BEFORE_MINUTES = 5;

    public function __construct(private readonly YouTubeOAuth $oauth) {}

    public function accessTokenFor(SocialAccount $account): string
    {
        $current = (string) $account->access_token;

        if ($current !== '' && $account->token_expires_at?->isAfter(now()->addMinutes(self::RENEW_BEFORE_MINUTES))) {
            return $current;
        }

        if (blank($account->refresh_token)) {
            throw new TokenInvalidException("YouTube kanal {$account->name} nema refresh token; poveži ga ponovno.");
        }

        $fresh = $this->oauth->refresh((string) $account->refresh_token);

        $account->forceFill([
            'access_token' => $fresh['access_token'],
            'token_expires_at' => $fresh['expires_at'],
            // Google does not rotate the refresh token; a renewal that carries none keeps the stored one.
            'refresh_token' => $fresh['refresh_token'] ?? $account->refresh_token,
        ])->save();

        return $fresh['access_token'];
    }
}
