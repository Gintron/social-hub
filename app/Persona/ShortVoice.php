<?php

declare(strict_types=1);

namespace App\Persona;

use App\Models\Brand;
use App\Voiceover\NarrationClip;
use App\Voiceover\Phonetizer;
use App\Voiceover\Script;
use App\Voiceover\ScriptGuard;
use App\Voiceover\ScriptLine;
use App\Voiceover\SpokenCroatian;
use App\Voiceover\SpokenEnglish;
use App\Voiceover\Synthesizer;
use App\Voiceover\VoiceoverException;

/**
 * The character's voice for an approved script: checked by ScriptGuard like every voice, every line spelled out in the
 * character's language (the hub says the numbers, not the model), the brand's words given their IPA, and spoken once — a
 * line said before is read from disk.
 *
 * Unlike a slideshow's narration this voice is the video: without it there is nothing to move the character's lips,
 * so it throws rather than leaving the short silent.
 */
final class ShortVoice
{
    public function __construct(
        private readonly ScriptGuard $guard,
        private readonly SpokenCroatian $croatian,
        private readonly SpokenEnglish $english,
        private readonly Phonetizer $phonetizer,
        private readonly Synthesizer $synthesizer,
    ) {}

    /**
     * @return list<NarrationClip> One per line of the script, in order.
     *
     * @throws VoiceoverException
     */
    public function speak(Brand $brand, Persona $persona, ShortScript $script): array
    {
        $settings = $brand->voiceoverSettings();

        if (($reason = $settings->whyNot()) !== null) {
            throw new VoiceoverException("Lik nema glas: {$reason}.", 'not_configured');
        }

        // The gate every voice passes before anything is paid: no web address read aloud, no script past its length. The
        // figures are not held to an item — the approved script is what the short is about.
        $violations = $this->guard->check(
            new Script(array_map(fn (array $line): ScriptLine => new ScriptLine($line['shot'], $line['text']), $script->lines)),
            [],
            amounts: false,
            characterLimit: (int) config('hub.persona.max_characters', 1200),
        );

        if ($violations !== []) {
            throw new VoiceoverException('Skripta nije prošla provjeru: '.implode(' ', $violations), 'script_rejected');
        }

        $spoken = array_map(
            fn (array $line): string => $persona->language === 'hr'
                ? $this->croatian->speak($line['text'], $settings->pronunciations)
                : $this->english->speak($line['text'], $settings->pronunciations),
            $script->lines,
        );

        // A model writing IPA on its own is an experiment for Croatian only; the brand's own list applies in any language.
        $spoken = $this->phonetizer->prepare(
            $spoken,
            $settings->ipaStyle(),
            $settings->words,
            $persona->language === 'hr' && $settings->autoIpa(),
            SpokenCroatian::pronounced($settings->pronunciations),
        );

        $clips = [];

        foreach ($spoken as $index => $text) {
            if (mb_trim($text) === '') {
                throw new VoiceoverException('Redak '.($index + 1).' nema ništa za izgovoriti.', 'script_rejected');
            }

            $clips[] = NarrationClip::from($this->synthesizer->clip($text, $settings, $brand));
        }

        return $clips;
    }
}
