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
     * v4 is the one model the hub speaks with (Marijan, 2026-10-03), and the newer one when ElevenLabs ships
     * it: change this and `models` below, nothing in the code names a model. It accepts every voice setting
     * used below (200 on all of them), but ignores `speed` — the same line comes back the same length at 0.7 and 1.2.
     */
    'model' => env('ELEVENLABS_MODEL', 'eleven_v4'),

    'output_format' => 'mp3_44100_128',

    /*
     * How the stress that OpenAI marks in a line (App\Voiceover\Accenter) is told to the voice: `acute` puts an
     * acute on the stressed vowel ("kúća"), `caps` makes it a capital ("kUća" — the trick ElevenLabs suggests
     * for models without phoneme tags), `off` sends the text as it is and asks nothing of OpenAI. A brand
     * may choose for itself. What a voice makes of either is heard, not read: hub:voiceover-test --compare.
     */
    'accents' => env('VOICEOVER_ACCENTS', 'acute'),

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
     * Models the panel offers, and the only ones a brand's setting is read as: anything else falls back to
     * `model` above. A newer model is one more line here. The note is what the editor reads.
     */
    'models' => [
        'eleven_v4' => 'v4 — jedini model koji hub koristi',
    ],
];
