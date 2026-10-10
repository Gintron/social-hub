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

    /*
     * The models that read IPA put into the text. Only v4: it is the one checked live (2026-10-02) with IPA in the
     * text. A model is not sent IPA until it is listed here, because it is not known what another one makes of it
     * (Multilingual v2 and Flash v2.5 were seen to leave out a word that had a pronunciation-dictionary rule). A line
     * for any other model is spoken as it is, and `hub:doctor` says so. A newer model goes here only after it has been
     * heard with `hub:voiceover-test --compare`.
     */
    'ipa_models' => ['eleven_v4'],

    'output_format' => 'mp3_44100_128',

    /*
     * How the words a voice says wrongly are put right: a brand lists them with their IPA (Brendovi → Voice-over → Riječi s
     * ručnim izgovorom; a person chose it by ear) and the hub puts it into the text the voice reads — as `tag`
     * (`<phoneme alphabet="ipa" ph="ˈlɛtka">letka</phoneme>`: the word stays, the sound beside it; about 45 more characters for
     * every such word, and ElevenLabs bills by the character), `slash` (`/ˈlɛtka/` in place of the word, 2 more) or `bare`
     * (`ˈlɛtka` in place of it, none more). `off` sends the text as it is. A brand may choose for itself. How each sounds is heard,
     * not read: hub:voiceover-test --compare. `tag` is the default because it is closest to the pronunciation dictionary that
     * sounded better in the Listo TikTok ad, and a few words of a few lines cost next to nothing.
     */
    'ipa' => env('VOICEOVER_IPA', 'tag'),

    /*
     * Also have OpenAI (App\Voiceover\Phonetizer) write the IPA of the words it thinks a voice says wrongly. Off: on 2026-10-02 a
     * real answer (Listo and Konzum, not letka) did not sound better than the plain text (Marijan's ear), while IPA chosen by a
     * person for the right words (letak, letka) did. Needs OPENAI_API_KEY.
     */
    'ipa_auto' => (bool) env('VOICEOVER_IPA_AUTO', false),

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
