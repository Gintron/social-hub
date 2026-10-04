<?php

declare(strict_types=1);

return [
    /*
     * Which TikTok product publishes: `developer` is the original Content Posting API, `business` is
     * TikTok API for Business (Organic / Accounts API).
     *
     * The developer track was refused production on 2026-09-22 — TikTok does not accept "a utility
     * tool to help upload contents to the account(s) you or your team manages" as a use case, which
     * is exactly what the hub is, so it stays unaudited (SELF_ONLY) forever. The business track has
     * no such wall. Switch once the app is approved there; see docs/tiktok-business-api.md.
     */
    'driver' => env('TIKTOK_API', 'developer'),

    'client_key' => env('TIKTOK_CLIENT_KEY'),
    'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    'redirect' => env('TIKTOK_REDIRECT_URI'),

    'api_base' => 'https://open.tiktokapis.com/v2',
    'authorize_url' => 'https://www.tiktok.com/v2/auth/authorize/',

    'business' => [
        'app_id' => env('TIKTOK_BUSINESS_APP_ID'),
        'app_secret' => env('TIKTOK_BUSINESS_APP_SECRET'),

        /*
         * Stricter than the developer track: absolute https, trailing slash, no query, no port.
         */
        'redirect' => env('TIKTOK_BUSINESS_REDIRECT_URI'),

        /*
         * TikTok generates this per activated redirect URL in the portal — it is not assembled from
         * a client id the way Login Kit's is. Copy it from My Apps > App Detail.
         */
        'authorize_url' => env('TIKTOK_BUSINESS_AUTHORIZE_URL'),

        'api_base' => 'https://business-api.tiktok.com/open_api/v1.3',

        /*
         * The hub posts a brand's own offers, so every post is Brand Organic ("Promotional content").
         * Branded Content is for paid partnerships and would override this.
         */
        'brand_organic' => (bool) env('TIKTOK_BUSINESS_BRAND_ORGANIC', true),

        /*
         * Leave the post's first comment through `/business/comment/create/` (App\Jobs\LeaveTikTokCommentJob).
         * Off until the app has been given the comment permission (scopes `comment.list.manage`, `comment.list`)
         * and every account has authorised it again — without them TikTok refuses. While it is off the job still
         * runs: it tells an admin the exact text to paste by hand, so what the video promises ("poveznica je u
         * komentaru") is kept either way. See docs/catalog-video.md.
         */
        'comments' => (bool) env('TIKTOK_BUSINESS_COMMENTS', false),
    ],

    /*
     * `video.publish` is Direct Post (the video appears on the account). `video.upload` only drops a
     * draft into the creator's inbox; keep both so a brand can choose.
     */
    'scopes' => ['user.info.basic', 'video.publish', 'video.upload', 'video.list'],

    'http_timeout' => (int) env('TIKTOK_HTTP_TIMEOUT', 30),

    /*
     * Until TikTok audits the app, every post it makes is private (SELF_ONLY) and at most five
     * creators may use it per day. The hub reads the allowed values from the API rather than
     * assuming, but this is the default it asks for.
     */
    'default_privacy_level' => env('TIKTOK_PRIVACY_LEVEL', 'SELF_ONLY'),
];
