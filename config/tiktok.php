<?php

declare(strict_types=1);

return [
    'client_key' => env('TIKTOK_CLIENT_KEY'),
    'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    'redirect' => env('TIKTOK_REDIRECT_URI'),

    'api_base' => 'https://open.tiktokapis.com/v2',
    'authorize_url' => 'https://www.tiktok.com/v2/auth/authorize/',

    /*
     * `video.publish` is Direct Post (the video appears on the account). `video.upload` only drops a
     * draft into the creator's inbox; keep both so a brand can choose.
     */
    'scopes' => ['user.info.basic', 'video.publish', 'video.upload'],

    'http_timeout' => (int) env('TIKTOK_HTTP_TIMEOUT', 30),

    /*
     * Until TikTok audits the app, every post it makes is private (SELF_ONLY) and at most five
     * creators may use it per day. The hub reads the allowed values from the API rather than
     * assuming, but this is the default it asks for.
     */
    'default_privacy_level' => env('TIKTOK_PRIVACY_LEVEL', 'SELF_ONLY'),
];
