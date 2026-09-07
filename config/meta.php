<?php

declare(strict_types=1);

return [
    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),

    // Pin a Graph API version; Meta keeps each version alive ~2 years.
    'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
    'graph_base' => 'https://graph.facebook.com',

    // Facebook Login for Business configuration id (dashboard → Facebook Login for Business → Configurations).
    'login_config_id' => env('META_LOGIN_CONFIG_ID'),
    'redirect' => env('META_REDIRECT_URI'),

    'scopes' => [
        'pages_show_list',
        'pages_manage_posts',
        'pages_read_engagement',
        'instagram_basic',
        'instagram_content_publish',
    ],

    'http_timeout' => (int) env('META_HTTP_TIMEOUT', 30),

    // Uploading a reel means Meta fetching the file from us; that call waits for the fetch.
    'upload_timeout' => (int) env('META_UPLOAD_TIMEOUT', 300),
];
