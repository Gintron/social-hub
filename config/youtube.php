<?php

declare(strict_types=1);

return [
    /*
     * The Google OAuth client of the Google Cloud project that holds the YouTube Data API. Its
     * "Authorized redirect URI" has to equal YOUTUBE_REDIRECT_URI exactly. Setup: docs/youtube.md.
     */
    'client_id' => env('YOUTUBE_CLIENT_ID'),
    'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
    'redirect' => env('YOUTUBE_REDIRECT_URI'),

    'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_url' => 'https://oauth2.googleapis.com/token',
    'api_base' => 'https://www.googleapis.com/youtube/v3',
    'upload_url' => 'https://www.googleapis.com/upload/youtube/v3/videos',

    /*
     * `youtube.upload` puts a video on the channel; `youtube.readonly` reads the channel and the
     * numbers under a post.
     */
    'scopes' => [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
    ],

    /*
     * Used when a variant does not say otherwise. An upload from a project that has not passed the
     * YouTube API audit is forced to private by Google, without an error; the hub records what came back.
     */
    'privacy' => env('YOUTUBE_PRIVACY', 'public'),

    // An upload needs a category. 22 is People & Blogs; it classifies the video and does not limit reach.
    'category_id' => env('YOUTUBE_CATEGORY_ID', '22'),

    /*
     * A Short is a vertical (or square) video of at most 3 minutes, the limit since 15 Oct 2024. The
     * title is what YouTube shows in full (100 characters); the description may be 5000 bytes.
     */
    'max_seconds' => 180,
    'title_chars' => 100,
    'description_bytes' => 5000,

    'http_timeout' => (int) env('YOUTUBE_HTTP_TIMEOUT', 30),

    /*
     * The video goes up in one request. The publish job is cut off after 120 s, so an upload still
     * running at 100 s fails cleanly as transient and the job retries with a new session.
     */
    'upload_timeout' => (int) env('YOUTUBE_UPLOAD_TIMEOUT', 100),
];
