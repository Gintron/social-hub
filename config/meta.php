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
        // Views and reach of published posts (App\Metrics). Add them to the Login Configuration in
        // the Meta dashboard too, then reconnect the accounts.
        'instagram_manage_insights',
        'read_insights',
        // Reactions, comments and shares of a Page post (App\Metrics\FacebookMetrics): without it
        // Graph answers "(#10) This endpoint requires the 'pages_read_user_content' permission".
        'pages_read_user_content',
        // The link of a Page post goes in its first comment (FacebookPagePublisher), and commenting
        // as the Page needs this.
        'pages_manage_engagement',
    ],

    /*
     * Insights metric names per kind of post, mapped onto post_metrics columns. Meta renames and
     * retires metrics between Graph versions; when one stops answering, change it here.
     */
    'insights' => [
        'instagram' => ['views' => 'views', 'reach' => 'reach', 'likes' => 'likes', 'comments' => 'comments', 'shares' => 'shares', 'saved' => 'saves'],
        // post_impressions_unique was retired on 15 June 2026; unique media views is Meta's reach now.
        'facebook_post' => ['post_media_view' => 'views', 'post_total_media_view_unique' => 'reach'],
        'facebook_reel' => ['blue_reels_play_count' => 'views', 'post_total_media_view_unique' => 'reach'],
    ],

    'http_timeout' => (int) env('META_HTTP_TIMEOUT', 30),

    // Uploading a reel means Meta fetching the file from us; that call waits for the fetch.
    'upload_timeout' => (int) env('META_UPLOAD_TIMEOUT', 300),
];
