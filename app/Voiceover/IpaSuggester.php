<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Ai\OpenAiClient;
use App\Ai\OpenAiException;

/**
 * A model's first guess at the IPA of one word a person is adding to a brand's list (Brendovi → Voice-over → Riječi s
 * ručnim izgovorom). It is a help for typing, never a decision: the person listens to it in the preview and changes it
 * until it sounds right, and only what they save is used.
 *
 * It is held to what any transcription of a word is held to (Ipa::clean), so a guess that is not one — another word, a
 * sentence, something with markup in it — is refused rather than put in the field.
 */
final class IpaSuggester
{
    private const INSTRUCTIONS = <<<'TXT'
Ti si lektor i fonetičar hrvatskoga standardnog jezika. Dobiješ jednu riječ (i, ako je ima, rečenicu u kojoj se pojavljuje) koju sintetizator govora čita krivo, a ti napiši kako se ta riječ izgovara, u IPA-i, u obliku u kojem je napisana. Ako riječ ima više mogućih naglasaka, odaberi onaj koji odgovara rečenici; bez rečenice odaberi najčešći.

TXT.Ipa::NOTATION.<<<'TXT'


Vrati samo transkripciju te jedne riječi (ipa), ne druge riječi i ne rečenicu.
TXT;

    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * @param  string|null  $context  A sentence the word is in, so that a word with two stresses gets the right one.
     * @return string|null The IPA, or null when the model's answer is not a transcription of the word.
     *
     * @throws VoiceoverException When the model cannot be asked.
     */
    public function suggest(string $word, ?string $context = null): ?string
    {
        $word = mb_trim($word);

        if (preg_match('/^\p{L}+$/u', $word) !== 1) {
            return null;
        }

        try {
            $result = $this->client->structured(
                model: (string) (config('openai.ipa.model') ?: config('openai.model')),
                system: self::INSTRUCTIONS,
                user: json_encode(array_filter(['word' => $word, 'sentence' => $context]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                name: 'ipa_suggestion',
                schema: [
                    'type' => 'object',
                    'properties' => ['ipa' => ['type' => 'string', 'description' => 'IPA te jedne riječi, s ˈ ispred naglašenog sloga; bez razmaka i zagrada.']],
                    'required' => ['ipa'],
                    'additionalProperties' => false,
                ],
                effort: config('openai.ipa.effort') ?: config('openai.effort'),
                maxOutputTokens: (int) config('openai.ipa.max_output_tokens', 12000),
                timeout: (int) config('openai.ipa.timeout', 60),
            );
        } catch (OpenAiException $e) {
            throw new VoiceoverException('Izgovor (IPA) nije mogao nastati: '.$e->getMessage(), 'ipa_'.$e->errorCode, retryable: $e->retryable, previous: $e);
        }

        $ipa = $result->data['ipa'] ?? null;

        return is_string($ipa) ? Ipa::clean($word, $ipa) : null;
    }
}
