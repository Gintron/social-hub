<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | ElevenLabs (voice-over)
    |--------------------------------------------------------------------------
    | Speech for the videos the hub renders. Without a key nothing here runs and every video is made
    | the way it always was, with the brand's music or in silence — a missing key is never an error
    | in a render. See docs/voiceover.md.
    */
    'api_key' => env('ELEVENLABS_API_KEY'),

    'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),

    /*
     * Multilingual v2 lists Croatian, reads numbers better than the fast models and accepts every
     * voice setting used below. A brand can pick another model in the panel (eleven_v4, eleven_v3).
     */
    'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),

    'output_format' => 'mp3_44100_128',

    'http_timeout' => (int) env('ELEVENLABS_HTTP_TIMEOUT', 45),

    /*
     * How long a render waits for another that is speaking the same words at the same moment, rather
     * than paying for them twice.
     */
    'lock_wait_seconds' => (int) env('ELEVENLABS_LOCK_WAIT_SECONDS', 60),

    /*
     * Where synthesised clips are kept. Private: Meta and TikTok fetch the finished video, never the
     * narration, so nothing here has to be reachable from outside.
     */
    'disk' => env('ELEVENLABS_DISK', 'local'),

    /*
     * A brand may override each of these in the panel. Stability a little above the default keeps the
     * voice steady from one clip to the next, which matters when a video is spoken clip by clip. A touch
     * above normal speed suits a short video, where a second saved on every slide is a second of
     * attention kept; prices stay clear well past this.
     */
    'voice_settings' => [
        'stability' => 0.55,
        'similarity_boost' => 0.75,
        'style' => 0.0,
        'use_speaker_boost' => true,
        'speed' => 1.05,
    ],

    /*
     * The most characters one video may send to the API. Scripts are a few hundred; this is the fence
     * against a runaway one (a hundred-item roundup, a pasted article) costing a month's credits — and
     * against a video longer than Facebook takes: 800 characters is about a minute of speech, and a
     * Facebook Reel may not run past 90 seconds.
     */
    'max_characters_per_video' => (int) env('ELEVENLABS_MAX_CHARACTERS_PER_VIDEO', 800),

    /*
     * Models the panel offers. `stitching` is not needed (clips are spoken independently); the note is
     * what the editor reads.
     */
    'models' => [
        'eleven_multilingual_v2' => 'Multilingual v2 — provjeren hrvatski i brojevi (preporučeno)',
        'eleven_v4' => 'v4 — najnoviji, ekspresivniji (isprobaj prije upotrebe)',
        'eleven_v3' => 'v3 — ekspresivan, ograničene postavke glasa',
        'eleven_flash_v2_5' => 'Flash v2.5 — jeftiniji, hrvatski nije službeno naveden',
    ],
];
