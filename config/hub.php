<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    | Only these e-mails may log into the Filament panel. Comma separated.
    */
    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HUB_ADMIN_EMAILS', ''))
    ))),

    'brand_default_timezone' => 'Europe/Zagreb',

    /*
    |--------------------------------------------------------------------------
    | Shelf life of an offer
    |--------------------------------------------------------------------------
    | How many days after the day of posting an item of this kind must still be valid, or
    | automation passes over it (App\Models\ContentItem::scopePostableAt). A shopper needs time
    | to act on an offer; a post about one that ends tomorrow sends them to a closed page.
    | Kinds that are not listed (jobs, articles, events) are only kept from being expired.
    */
    'min_days_valid' => [
        'deal' => 3,
        'comparison' => 3,
        // Letak koji istječe za dan-dva ne bi imao što pokazati kupcu koji video vidi s kašnjenjem.
        'catalog' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | How long an item stays news
    |--------------------------------------------------------------------------
    | Days after `published_at` that automation still posts an item of this kind. A leaflet is
    | news the day it comes out: without this, the first sync of a source that already holds
    | a month of leaflets would queue videos for all of them. Kinds not listed never age out
    | (a deal or a job stays worth posting as long as it is valid).
    */
    'max_age_days' => [
        'catalog' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media rendering (spatie/browsershot)
    |--------------------------------------------------------------------------
    */
    'media_disk' => env('HUB_MEDIA_DISK', 'public'),

    'render' => [
        'node_binary' => env('HUB_NODE_BINARY'),
        'npm_binary' => env('HUB_NPM_BINARY'),
        'chrome_path' => env('HUB_CHROME_PATH'),
        'timeout' => (int) env('HUB_RENDER_TIMEOUT', 90),
        'jpeg_quality' => 90,
        // An original, bundled loop gives the job brand audible videos out of the box. A track
        // uploaded in the panel still takes precedence; --audio=none still forces silence.
        'default_audio' => [
            'studentski-poslovi' => resource_path('audio/studentski-pulse.m4a'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source synchronisation (Social Feed v1)
    |--------------------------------------------------------------------------
    */
    'sync' => [
        'overlap_minutes' => 60,
        'page_limit' => 50,
        'http_timeout' => (int) env('HUB_SYNC_HTTP_TIMEOUT', 20),
        'max_pages' => 50,
    ],

    'feed_schema_path' => base_path('docs/social-feed-v1.schema.json'),

    /*
    |--------------------------------------------------------------------------
    | Platform limits enforced hub-side before calling any API
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'ig_daily_posts' => 100,
        'ig_caption_chars' => 2200,
        'ig_hashtags' => 30,
        'ig_image_max_bytes' => 8 * 1024 * 1024,
        'ig_min_ratio' => 0.8,
        'ig_max_ratio' => 1.91,
        'fb_caption_chars' => 63206,
        // How long to wait for the item's own landing page before treating it as dead (App\Publishing\LinkPreflight).
        'link_preflight_timeout' => (int) env('HUB_LINK_PREFLIGHT_TIMEOUT', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI characters (App\Persona, docs/ai-persona.md)
    |--------------------------------------------------------------------------
    | `recent_days`: how far back the topics a character already had are shown to the model, so it does not offer them
    | again. `max_seconds`: how long a short's script may run when spoken. `words_per_second`: speaking pace, for the
    | estimate the panel shows before anything is paid for.
    */
    'persona' => [
        'recent_days' => (int) env('HUB_PERSONA_RECENT_DAYS', 60),
        'max_seconds' => (int) env('HUB_PERSONA_MAX_SECONDS', 60),
        // What the voice of one short may cost: characters actually spoken (a 60-second script is ~900).
        'max_characters' => (int) env('HUB_PERSONA_MAX_CHARACTERS', 1200),
        'words_per_second' => ['en' => 2.6, 'hr' => 2.3],
    ],
];
