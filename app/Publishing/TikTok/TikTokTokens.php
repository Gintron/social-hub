<?php

declare(strict_types=1);

namespace App\Publishing\TikTok;

/**
 * What both TikTok tracks have to provide, so the connect flow and hub:refresh-tiktok-tokens do not
 * care which one is configured. The shapes match: a day-long access token, a year-long refresh
 * token that is handed back renewed, and an `open_id` identifying the account.
 */
interface TikTokTokens
{
    public function authorizeUrl(string $state): string;

    /**
     * @return array{access_token: string, refresh_token: string, open_id: string, expires_at: \Carbon\CarbonImmutable, refresh_expires_at: \Carbon\CarbonImmutable|null, scope: string}
     */
    public function exchangeCode(string $code): array;

    /**
     * @return array{access_token: string, refresh_token: string, open_id: string, expires_at: \Carbon\CarbonImmutable, refresh_expires_at: \Carbon\CarbonImmutable|null, scope: string}
     */
    public function refresh(string $refreshToken): array;

    public function redirectUri(): string;
}
