<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Voiceover;
use App\Voiceover\Accenter;
use App\Voiceover\ElevenLabsClient;
use App\Voiceover\SpokenCroatian;
use App\Voiceover\Stress;
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
        {--accents= : How the stress is told to the voice: acute, caps or off (default: the brand\'s)}
        {--compare : Speak the line in every style of stress marking, to hear which one the voice makes the most of}
        {--list-voices : List the account\'s voices and stop}';

    protected $description = 'Speak one line with ElevenLabs the way a video would, and show what the voice received';

    public function handle(ElevenLabsClient $client, SpokenCroatian $spoken, Accenter $accenter, Synthesizer $synthesizer): int
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
                $settings = VoiceoverSettings::fromArray([
                    'voice_id' => (string) $this->option('voice'),
                    'model' => $settings->model,
                    'speed' => $settings->speed,
                    'stability' => $settings->stability,
                    'accents' => $settings->accents,
                    'pronunciations' => array_map(fn (string $find, string $say): array => ['find' => $find, 'say' => $say], array_keys($settings->pronunciations), $settings->pronunciations),
                ]);
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

            $requested = filled($this->option('accents')) ? (string) $this->option('accents') : $settings->accents;

            if (! in_array($requested, Stress::STYLES, true)) {
                $this->error('--accents mora biti acute, caps ili off.');

                return self::FAILURE;
            }

            $styles = $this->option('compare') ? Stress::STYLES : [$requested];
            $rows = [];

            foreach ($styles as $style) {
                // The marks are asked for once and kept, so comparing three styles costs three clips and one request.
                $said = $accenter->prepare([$words], $style)[0];
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
        $this->table(['Naglasci', 'Glas', 'Model', 'Brzina', 'Znakova', 'Kredita', 'Trajanje', 'Glasnoća', 'Izvor'], array_map(fn (array $row): array => [
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
