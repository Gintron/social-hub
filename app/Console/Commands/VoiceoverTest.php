<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Voiceover;
use App\Voiceover\ElevenLabsClient;
use App\Voiceover\Ipa;
use App\Voiceover\Phonetizer;
use App\Voiceover\SpokenCroatian;
use App\Voiceover\Synthesizer;
use App\Voiceover\VoiceoverException;
use App\Voiceover\VoiceoverSettings;
use Illuminate\Console\Command;

final class VoiceoverTest extends Command
{
    protected $signature = 'hub:voiceover-test
        {text? : What to say (default: a sample deal line)}
        {--brand= : Brand slug: its voice, model, speed and pronunciations}
        {--voice= : Voice id, instead of the brand\'s}
        {--ipa= : How the IPA of the words is put into the text: tag, slash, bare or off (default: the brand\'s)}
        {--word=* : A word with its IPA to try, as "word=ipa" (repeat for several), on top of the brand\'s own; nothing is saved}
        {--auto : Also have OpenAI write the IPA of other words (default: as the brand has it)}
        {--compare : Speak the line without IPA and in every style of it, to hear which one the voice makes the most of}
        {--list-voices : List the account\'s voices and stop}';

    protected $description = 'Speak one line with ElevenLabs the way a video would, and show what the voice received';

    public function handle(ElevenLabsClient $client, SpokenCroatian $spoken, Phonetizer $phonetizer, Synthesizer $synthesizer): int
    {
        if (! $client->configured()) {
            $this->error('ELEVENLABS_API_KEY nije postavljen (.env).');

            return self::FAILURE;
        }

        try {
            if ($this->option('list-voices')) {
                return $this->voices($client);
            }

            $brand = filled($this->option('brand')) ? Brand::query()->where('slug', (string) $this->option('brand'))->firstOrFail() : null;
            $settings = $brand?->voiceoverSettings() ?? VoiceoverSettings::fromArray([]);

            if (filled($this->option('voice'))) {
                $settings = $settings->withVoice((string) $this->option('voice'));
            }

            $extra = $this->extraWords();

            if ($extra === null) {
                return self::FAILURE;
            }

            if ($extra !== [] || $this->option('auto')) {
                $settings = $settings->withIpa($settings->ipa, [...$settings->words, ...$extra], $this->option('auto') ? true : null);
            }

            if ($settings->voiceId === null) {
                $this->error('Nema glasa: postavi ga na brendu (Voice-over) ili daj --voice=ID. Popis: --list-voices.');

                return self::FAILURE;
            }

            $text = (string) ($this->argument('text') ?: 'Kruh bijeli 500 g u trgovini Konzum za 1,49 €. Popust 25 %. Vrijedi do 30.09.2026.');
            $words = $spoken->speak($text, $settings->pronunciations);

            if ($words === '') {
                $this->error('Nema što izgovoriti.');

                return self::FAILURE;
            }

            $requested = filled($this->option('ipa')) ? (string) $this->option('ipa') : $settings->ipa;

            if (! in_array($requested, Ipa::STYLES, true)) {
                $this->error('--ipa mora biti tag, slash, bare ili off.');

                return self::FAILURE;
            }

            if (! $settings->takesIpa() && $requested !== Ipa::OFF) {
                $this->warn("Model {$settings->model} ne čita IPA u tekstu (samo ".implode(' i ', (array) config('elevenlabs.ipa_models')).'): izgovor se ne šalje, svi isječci su obični tekst.');
                $requested = Ipa::OFF;
            }

            if ($settings->words === [] && ! $settings->ipaAuto && $requested !== Ipa::OFF) {
                $this->warn('Brend nema riječi s ručnim izgovorom (a nijedna nije dana s --word, ni --auto): sve će zvučati kao obični tekst.');
            }

            $styles = $this->option('compare') && $settings->takesIpa() ? Ipa::STYLES : [$requested];
            $rows = [];

            foreach ($styles as $style) {
                // A model's IPA (--auto) is asked for once and kept, so comparing four styles costs four clips and at most one request.
                // The words the pronunciation list has said get no IPA, as in a video (Phonetizer::prepare).
                $said = $phonetizer->prepare([$words], $style, $settings->words, $settings->autoIpa(), SpokenCroatian::pronounced($settings->pronunciations))[0];
                $cached = Voiceover::query()->where('hash', Synthesizer::hash($said, $settings))->exists();
                $rows[] = [$style, $said, $synthesizer->clip($said, $settings, $brand), $cached];
            }
        } catch (VoiceoverException $e) {
            $this->error($e->getMessage().' ['.$e->errorCode.']');

            return self::FAILURE;
        }

        $this->line('Napisano:  '.$text);

        foreach ($rows as [$style, $said]) {
            $this->line(($this->option('compare') ? 'Glas čita ('.$style.'): ' : 'Glas čita: ').$said);
        }

        $this->newLine();
        $this->table(['Izgovor', 'Glas', 'Model', 'Brzina', 'Znakova', 'Kredita', 'Trajanje', 'Glasnoća', 'Izvor'], array_map(fn (array $row): array => [
            $row[0],
            $settings->voiceId,
            $settings->model,
            $settings->voiceSettings()['speed'],
            $row[2]->characters,
            $row[2]->cost ?? '—',
            number_format($row[2]->durationSeconds(), 1, ',', '').' s',
            $row[2]->loudness_lufs === null ? '—' : number_format($row[2]->loudness_lufs, 1, ',', '').' LUFS',
            $row[3] ? 'iz keša (nije naplaćeno)' : 'ElevenLabs (naplaćeno)',
        ], $rows));

        foreach ($rows as [$style, , $clip]) {
            $this->line(($this->option('compare') ? $style.': ' : 'Zapis: ').$clip->absolutePath());
        }

        return self::SUCCESS;
    }

    /**
     * The `--word word=ipa` options as a list of words with their IPA; null (and a message) when one is not of that shape.
     *
     * @return array<string, string>|null
     */
    private function extraWords(): ?array
    {
        $words = [];

        foreach ((array) $this->option('word') as $given) {
            [$word, $ipa] = array_pad(explode('=', (string) $given, 2), 2, '');
            $word = mb_trim($word);

            if (preg_match('/^\p{L}+$/u', $word) !== 1 || Ipa::sanitize($ipa) === null) {
                $this->error("--word mora biti oblika riječ=ipa (npr. --word=\"letka=ˈlɛtka\"), a dobio sam „{$given}“.");

                return null;
            }

            $words[$word] = Ipa::sanitize($ipa);
        }

        return $words;
    }

    private function voices(ElevenLabsClient $client): int
    {
        $voices = $client->voices(fresh: true);

        if ($voices === []) {
            $this->warn('Račun nema glasova.');

            return self::SUCCESS;
        }

        $this->line('★ = ElevenLabs navodi glas kao provjeren za hrvatski.');
        $this->table(['ID', 'Glas'], array_map(fn (string $id, string $label): array => [$id, $label], array_keys($voices), $voices));

        return self::SUCCESS;
    }
}
