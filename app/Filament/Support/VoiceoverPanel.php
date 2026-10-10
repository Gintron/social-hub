<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Ai\OpenAiClient;
use App\Models\Brand;
use App\Models\Voiceover;
use App\Voiceover\ElevenLabsClient;
use App\Voiceover\Ipa;
use App\Voiceover\IpaSuggester;
use App\Voiceover\Phonetizer;
use App\Voiceover\SpokenCroatian;
use App\Voiceover\Synthesizer;
use App\Voiceover\VoiceoverException;
use App\Voiceover\VoiceoverSettings;
use Illuminate\Support\Facades\Cache;

/**
 * What the panel needs from ElevenLabs, without ever making a page wait for it: the voices to choose
 * from, whether the connection works, and a sample of a voice.
 *
 * A failure is remembered for a minute, so an unreachable API costs the first page one short wait and
 * the following ones nothing.
 */
final class VoiceoverPanel
{
    public const SAMPLE = 'Kruh bijeli 500 g u trgovini Konzum za 1,49 €. Popust 25 %. Vrijedi do 30.09.2026.';

    private const VOICES_FAILED = 'voiceover.panel.voices_failed';

    private const SUBSCRIPTION = 'voiceover.panel.subscription';

    /**
     * The account's voices as `voice_id => label`; empty when there is no key or the list cannot be read.
     *
     * @return array<string, string>
     */
    public static function voices(): array
    {
        $client = app(ElevenLabsClient::class);

        if (! $client->configured() || Cache::has(self::VOICES_FAILED)) {
            return [];
        }

        try {
            return $client->voices();
        } catch (VoiceoverException $e) {
            Cache::put(self::VOICES_FAILED, $e->getMessage(), 60);

            return [];
        }
    }

    public static function voicesError(): ?string
    {
        $error = Cache::get(self::VOICES_FAILED);

        return is_string($error) ? $error : null;
    }

    /**
     * Whether the hub can speak at all, how much of the plan is left, and what is said about the words' IPA.
     *
     * @param  string|null  $ipa  What the form has chosen for the way IPA is written; null or blank is the default.
     * @param  string|null  $model  The form's model; null or blank is the default.
     * @param  array<mixed>  $words  The form's rows of words with an IPA.
     * @param  bool|null  $auto  Whether the form has a model write IPA too; null is the default.
     */
    public static function status(?string $ipa = null, ?string $model = null, array $words = [], ?bool $auto = null): string
    {
        $settings = VoiceoverSettings::fromArray(['model' => $model, 'ipa' => $ipa, 'words' => $words, 'ipa_auto' => $auto]);

        return self::speech().' '.match (true) {
            $settings->ipaIgnored() => 'Model '.$settings->model.' ne čita IPA u tekstu (samo '.implode(' i ', (array) config('elevenlabs.ipa_models')).'), pa se izgovor ne šalje: glas čita tekst kakav jest.',
            $settings->ipa === Ipa::OFF => 'Izgovor (IPA) je isključen: glas čita tekst kakav jest.',
            $settings->autoIpa() && ! app(OpenAiClient::class)->configured() => 'OpenAI nije spojen: postavi OPENAI_API_KEY — bez njega glas ne nastaje, osim ako se isključi „model piše izgovor i za ostale riječi“.',
            $settings->autoIpa() => 'Izgovor: '.count($settings->words).' ručnih riječi, a OpenAI ('.(config('openai.ipa.model') ?: config('openai.model')).') piše IPA i za ostale.',
            default => 'Izgovor: '.count($settings->words).' ručnih riječi.',
        };
    }

    /**
     * A sample of the voice as the brand form has it now — before it is saved — and the words as the voice
     * receives them, so the way numbers are spelled can be checked by ear and by eye.
     *
     * @param  array<string, mixed>  $state  The form's `voiceover` values.
     * @param  bool  $withoutIpa  Speak it as if no word had an IPA, to hear what the IPA changes.
     * @return array{clip: Voiceover, spoken: string}
     *
     * @throws VoiceoverException
     */
    public static function sample(array $state, string $text, ?Brand $brand = null, bool $withoutIpa = false): array
    {
        $settings = VoiceoverSettings::fromArray($state);

        if ($withoutIpa) {
            $settings = $settings->withIpa(Ipa::OFF);
        }

        if (($reason = $settings->whyNot()) !== null) {
            throw new VoiceoverException("Glas se ne može preslušati: {$reason}.", 'not_configured');
        }

        $spoken = app(SpokenCroatian::class)->speak($text, $settings->pronunciations);

        if ($spoken === '') {
            throw new VoiceoverException('Nema što izgovoriti.', 'empty_script');
        }

        // The way a video's lines go: with the IPA of the words in the text, so what is heard here is what will be heard there.
        [$spoken] = app(Phonetizer::class)->prepare([$spoken], $settings->ipaStyle(), $settings->words, $settings->autoIpa(), SpokenCroatian::pronounced($settings->pronunciations));

        return ['clip' => app(Synthesizer::class)->clip($spoken, $settings, $brand), 'spoken' => $spoken];
    }

    /**
     * A model's first guess at the IPA of a word being added to a brand's list, for the field to be filled with —
     * or why there is none. Never throws: the form stays as it is.
     *
     * @return array{ipa: string|null, error: string|null}
     */
    public static function suggestIpa(string $word): array
    {
        if (preg_match('/^\p{L}+$/u', mb_trim($word)) !== 1) {
            return ['ipa' => null, 'error' => 'Upiši prvo riječ (samo slova).'];
        }

        try {
            $ipa = app(IpaSuggester::class)->suggest($word);
        } catch (VoiceoverException $e) {
            return ['ipa' => null, 'error' => $e->getMessage()];
        }

        return $ipa === null
            ? ['ipa' => null, 'error' => 'Model nije vratio izgovor koji bi bio ta riječ. Upiši ga rukom.']
            : ['ipa' => $ipa, 'error' => null];
    }

    /**
     * The ElevenLabs half of the status line.
     */
    private static function speech(): string
    {
        $client = app(ElevenLabsClient::class);

        if (! $client->configured()) {
            return 'ElevenLabs nije spojen: postavi ELEVENLABS_API_KEY u .env poslužitelja (docs/voiceover.md). Do tada se videi rade kao i prije.';
        }

        /** @var array<string, mixed> $subscription */
        $subscription = Cache::remember(self::SUBSCRIPTION, 300, function () use ($client): array {
            try {
                return $client->subscription();
            } catch (VoiceoverException $e) {
                return ['error' => $e->getMessage(), 'code' => $e->errorCode];
            }
        });

        if (isset($subscription['error'])) {
            // The key may be scoped to speech only; that is a working key.
            return ($subscription['code'] ?? null) === 'missing_permissions'
                ? 'ElevenLabs je spojen (ključ ne smije čitati pretplatu, pa se preostali znakovi ne vide).'
                : 'ElevenLabs: '.$subscription['error'];
        }

        $left = max(0, (int) $subscription['character_limit'] - (int) $subscription['character_count']);
        $reset = $subscription['next_character_count_reset_unix'] !== null
            ? ', obnova '.date('d.m.Y.', (int) $subscription['next_character_count_reset_unix'])
            : '';

        return sprintf(
            'ElevenLabs je spojen · plan %s · ostalo %s od %s znakova%s.',
            $subscription['tier'] ?? '—',
            number_format($left, 0, ',', '.'),
            number_format((int) $subscription['character_limit'], 0, ',', '.'),
            $reset,
        );
    }
}
