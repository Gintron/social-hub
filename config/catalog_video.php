<?php

declare(strict_types=1);

/*
 * The "new catalog" video (App\Catalog\*, resources/catalog-video): a 9:16 video of about 16 seconds in which three
 * products are tapped on the real leaflet and land on a list, made when a source publishes a `kind: catalog` item.
 * See docs/catalog-video.md.
 */
return [
    /*
     * Brand colours of the video (the app's own screens keep the app's colours). Listo's, by the owner's brief.
     */
    'theme' => [
        'cream' => '#F4F1E9',
        'green' => '#2F6B45',
        'ink' => '#1F2A21',
        'soft' => '#E4EBDC',
    ],

    /*
     * The call to action the video says and shows, by the owner's decision of 3 Oct 2026: where the link is, not what the
     * app costs. `comment` is true on Facebook and on Instagram, where the hub leaves the first comment. TikTok has
     * no commenting in the hub (docs/catalog-video.md § TikTok), so what a TikTok video should say is open: `bio`
     * says "Poveznica do aplikacije je u biografiji." and changes nothing else.
     */
    'link_in' => [
        'fb_page' => 'comment',
        'ig_business' => 'comment',
        'tiktok' => 'comment',
    ],

    /*
     * The sound the app makes when a product is added (Listo: app/assets/sounds/added.wav), placed on the frame of every
     * tap, and how far below the voice it plays, in dB.
     */
    'add_sound' => resource_path('catalog-video/sounds/added.wav'),
    'add_sound_gain_db' => -4.0,

    /*
     * Loudness of the finished video: where short video is mixed, and the highest peak the platforms accept without
     * clipping after their own re-encode.
     */
    'loudness' => ['lufs' => -14.0, 'true_peak' => -1.5, 'codec_headroom_db' => 0.5],

    /*
     * The ceiling of the mix before its level is set (see CatalogVideoRenderer::limiter()): the peaks of speech
     * and of the "added" sound are above its loudness, and bringing the mix to −14 LUFS needs room for them.
     */
    'premix_ceiling_db' => -3.5,

    /*
     * How long Chromium may take over the frames, and how long ffmpeg over the mix and the encode.
     */
    'frames_timeout' => (int) env('CATALOG_VIDEO_FRAMES_TIMEOUT', 420),
    'encode_timeout' => (int) env('CATALOG_VIDEO_ENCODE_TIMEOUT', 300),

    'jpeg_quality' => 94,
    'crf' => 19,
];
