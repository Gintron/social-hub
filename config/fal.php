<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | fal.ai (video of AI characters)
    |--------------------------------------------------------------------------
    | The pictures of an AI character's short (App\Persona\ShortProducer): the character talking to camera, lips driven
    | by the hub's own ElevenLabs voice, and the scenes between. Video only — every text the hub writes stays with OpenAI.
    | Model names are here, never in code; a model that wins a comparison is one line.
    */
    'key' => env('FAL_KEY'),

    'queue_url' => env('FAL_QUEUE_URL', 'https://queue.fal.run'),

    'models' => [
        // Image of the character + speech audio → the character saying it. Output runs as long as the audio.
        'talking' => env('FAL_TALKING_MODEL', 'fal-ai/kling-video/ai-avatar/v2/standard'),
        // A scene from words, vertical, without its own sound (the voice goes on over it).
        'broll' => env('FAL_BROLL_MODEL', 'fal-ai/kling-video/v3/standard/text-to-video'),
    ],

    // The lengths the scene model takes, in seconds; a line is covered by the shortest that is long enough.
    'broll_seconds' => ['min' => 3, 'max' => 15],

    /*
     * What a second costs, in US dollars, for the estimate a person sees before asking for a video, and for the ceiling
     * below. fal does not say what a request cost; these are its listed prices.
     */
    'price_per_second' => [
        'talking' => (float) env('FAL_TALKING_PRICE', 0.0562),
        'broll' => (float) env('FAL_BROLL_PRICE', 0.084),
    ],

    // A short whose estimate is above this is not made: a script that runs long should be cut, not paid for.
    'max_cost_per_video' => (float) env('FAL_MAX_COST_PER_VIDEO', 8.0),

    /*
     * The job that makes a short does not wait inside one run (the worker stops a job after 180 s): it comes back every
     * `poll_seconds` to ask fal, for at most `max_wait_minutes` from when the video was asked for.
     */
    'poll_seconds' => (int) env('FAL_POLL_SECONDS', 30),
    'max_wait_minutes' => (int) env('FAL_MAX_WAIT_MINUTES', 45),

    'timeout' => (int) env('FAL_TIMEOUT', 60),
];
