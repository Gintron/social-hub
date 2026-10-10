<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    | The hub's one AI provider. It writes the captions of the drafting agent (hub:agent-draft) and
    | writes how the words of what a voice-over is about to say are pronounced, in IPA (App\Voiceover\Phonetizer).
    | Without a key the agent does not run, and a brand that wants IPA put into the text gets no voice.
    */
    'api_key' => env('OPENAI_API_KEY'),

    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

    /*
     * How long one request may take. A model that thinks before it answers needs seconds to a minute;
     * a request that has not answered by then is not going to.
     */
    'timeout' => (int) env('OPENAI_TIMEOUT', 90),

    /*
     * The model for captions. GPT-6 Sol is the middle of the family: as good at Croatian as the flagship for
     * a few short paragraphs, at a fifth of the price. Change it here, not in code.
     */
    'model' => env('OPENAI_MODEL', 'gpt-6-sol'),

    /*
     * How much the model thinks before it answers: none, low, medium, high, xhigh or max, as far as the model
     * offers it. Blank sends no setting at all — the right thing for a model that does not reason.
     */
    'effort' => env('OPENAI_EFFORT', 'medium'),

    /*
     * Ceiling for one answer, thinking included. Captions are a few hundred tokens; the rest is room to think.
     */
    'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 16000),

    /*
     * The voice-over's IPA. Blank model or effort means "the same as for captions". The answer is a handful of
     * words; the ceiling is room to think, which counts against it, and a render that runs out of it goes without a
     * voice. The model is the part that matters: how Croatian is stressed is something it has to know rather than
     * work out.
     */
    'ipa' => [
        'model' => env('OPENAI_IPA_MODEL'),
        'effort' => env('OPENAI_IPA_EFFORT'),
        'max_output_tokens' => (int) env('OPENAI_IPA_MAX_OUTPUT_TOKENS', 12000),
        'timeout' => (int) env('OPENAI_IPA_TIMEOUT', 60),
    ],
];
