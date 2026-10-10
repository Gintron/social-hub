<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Ai\OpenAiClient;
use App\Ai\OpenAiException;
use App\Models\VoiceoverTranscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * What a model writes as the IPA of the words of a line, for the voice that is about to say it (an experiment, off
 * unless a brand asks: the words a voice gets wrong are better chosen by a person who has heard it).
 *
 * A voice that reads Croatian gets a rare word, a name or a word that is spelled like another wrong now
 * and then. A model that knows the language may know which. It is shown the line and asked for the IPA of the words
 * a voice might say wrongly — never to rewrite the line — and each answer is checked by code (Ipa::clean) before it is
 * believed: it has to be a transcription of that word and nothing else, otherwise it is dropped and the word is said
 * as it was. The model can add how a word sounds; it cannot change which words are said.
 *
 * What it answers is kept (`voiceover_transcriptions`), so a line is transcribed once: the same text goes to the
 * voice in every video, and a clip already paid for is found again.
 */
final class Phonetizer
{
    /**
     * Raise it when the instructions or the way an answer is read change: lines are then transcribed again.
     */
    private const VERSION = 1;

    private const INSTRUCTIONS = <<<'TXT'
Ti si lektor i fonetičar hrvatskoga standardnog jezika. Rečenice koje dobiješ naglas čita sintetizator govora, a tvoj je zadatak za riječi koje bi on mogao pročitati krivo napisati izgovor u IPA-i.

Svaka je rečenica već raspisana riječima: brojevi, iznosi i datumi su izgovoreni, pa ih ne diraš. Uz rečenicu dobiješ popis riječi koje smiješ transkribirati, svaku s rednim brojem u rečenici (word, od 0). Rečenice imaju redni broj (line, od 0).

Za svaku riječ koju treba transkribirati vrati broj rečenice (line), broj riječi (word) i transkripciju te riječi (ipa): samo te jedne riječi, kako se izgovara u toj rečenici, u obliku u kojem je napisana. Ne mijenjaj, ne dodaj i ne izostavljaj riječi: ipa je izgovor riječi iz popisa, ne druge riječi.

TXT.Ipa::NOTATION.<<<'TXT'


Što transkribirati:
- riječi koje se pišu jednako, a naglašavaju se različito ovisno o značenju ili obliku: naglasak odaberi prema smislu rečenice;
- vlastita imena (osobe, mjesta), nazive trgovina i marki te posuđenice, kako ih izgovara hrvatski govornik;
- rjeđe riječi i oblike u kojima naglasak nije na prvom slogu, pa ga je lako pogrešno postaviti.

Što ne transkribirati: sve što nije na popisu, prijedloge, veznike i čestice te uobičajene riječi u kojima nema dvojbe.

Bolje je riječ izostaviti nego transkribirati krivo: ako nisi siguran, ne transkribiraj je. Ako nijednu riječ ne treba, vrati praznu listu.
TXT;

    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * The lines as the voice is to read them, with the IPA of the words told in `$style`: the words a person gave an
     * IPA for and — when `$auto` — those a model writes one for. A line with nothing to say about is returned as it was;
     * so is everything when the style is off, or when nothing is asked of the model, without a request.
     *
     * A word the pronunciation list has already said (`$pronounced`, see SpokenCroatian::pronounced) gets no IPA, by a
     * person or by a model: the pronunciation is what is heard there, and the IPA is not put on top of it.
     *
     * @param  list<string>  $lines  Spelled out by SpokenCroatian, pronunciation list applied.
     * @param  array<string, string>  $words  Word => IPA, written by a person; what they gave is not second-guessed.
     * @param  bool  $auto  Also ask the model which of the other words a voice may say wrongly, and for their IPA.
     * @param  list<string>  $pronounced  Words that the pronunciation list says in these lines.
     * @return list<string>
     *
     * @throws VoiceoverException When the model's IPA is asked for and cannot be had; a voice is not made without it.
     */
    public function prepare(array $lines, string $style, array $words = [], bool $auto = false, array $pronounced = []): array
    {
        if ($style === Ipa::OFF) {
            return $lines;
        }

        $lines = array_map(Ipa::normalize(...), $lines);
        $written = $auto ? $this->marks($lines) : array_fill(0, count($lines), []);
        $exempt = array_fill_keys(array_map(mb_strtolower(...), $pronounced), true);

        return array_map(
            // A person's word comes first: the first mark for a word is the one that counts.
            fn (string $line, array $model): string => Ipa::render($line, [
                ...$this->unpronounced($line, Ipa::marksIn($line, $words), $exempt),
                ...$this->unpronounced($line, $model, $exempt),
            ], $style),
            $lines,
            $written,
        );
    }

    /**
     * The transcriptions of each line, from what was kept or — for the lines nobody has transcribed — from one request.
     *
     * @param  list<string>  $lines
     * @return list<list<array{word: int, ipa: string}>> The words and their IPA, one list for each line.
     *
     * @throws VoiceoverException
     */
    public function marks(array $lines): array
    {
        $model = $this->model();
        $hashes = [];

        foreach ($lines as $index => $line) {
            if (array_any(Ipa::words($line), fn (array $word): bool => Ipa::markable($word['text']))) {
                $hashes[$index] = $this->hash($line, $model);
            }
        }

        $known = $hashes === []
            ? collect()
            : VoiceoverTranscription::query()->whereIn('hash', array_values(array_unique($hashes)))->get()->keyBy('hash');

        $unknown = [];

        foreach ($hashes as $index => $hash) {
            if (! $known->has($hash)) {
                $unknown[$hash] = $lines[$index];
            }
        }

        if ($unknown !== []) {
            foreach ($this->ask($unknown, $model) as $hash => $transcription) {
                $known->put($hash, $transcription);
            }
        }

        return array_map(
            fn (int $index): array => isset($hashes[$index]) ? array_values((array) $known->get($hashes[$index])->marks) : [],
            array_keys($lines),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'marks' => [
                    'type' => 'array',
                    'description' => 'Riječi kojima treba napisati izgovor; prazna lista ako nijednoj.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'line' => ['type' => 'integer', 'description' => 'Broj rečenice (line).'],
                            'word' => ['type' => 'integer', 'description' => 'Broj riječi (word) u toj rečenici.'],
                            'ipa' => ['type' => 'string', 'description' => 'IPA te jedne riječi, s ˈ ispred naglašenog sloga; bez razmaka i zagrada.'],
                        ],
                        'required' => ['line', 'word', 'ipa'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['marks'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The marks that are not on a word the pronunciation list has said. `$exempt` is keyed by lower-case word.
     *
     * @param  list<array{word: int, ipa: string}>  $marks
     * @param  array<string, bool>  $exempt
     * @return list<array{word: int, ipa: string}>
     */
    private function unpronounced(string $line, array $marks, array $exempt): array
    {
        if ($exempt === []) {
            return $marks;
        }

        $found = Ipa::words($line);

        return array_values(array_filter(
            $marks,
            fn (array $mark): bool => ! isset($exempt[mb_strtolower($found[$mark['word']]['text'] ?? '')]),
        ));
    }

    /**
     * @param  array<string, string>  $unknown  Line by hash.
     * @return array<string, VoiceoverTranscription>
     *
     * @throws VoiceoverException
     */
    private function ask(array $unknown, string $model): array
    {
        $hashes = array_keys($unknown);
        $lines = array_values($unknown);
        $payload = [];

        foreach ($lines as $number => $line) {
            $words = [];

            foreach (Ipa::words($line) as $index => $word) {
                if (Ipa::markable($word['text'])) {
                    $words[] = ['word' => $index, 'text' => $word['text']];
                }
            }

            $payload[] = ['line' => $number, 'text' => $line, 'words' => $words];
        }

        try {
            $result = $this->client->structured(
                model: $model,
                system: self::INSTRUCTIONS,
                user: json_encode(['lines' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                name: 'ipa_transcriptions',
                schema: self::schema(),
                effort: config('openai.ipa.effort') ?: config('openai.effort'),
                maxOutputTokens: (int) config('openai.ipa.max_output_tokens', 12000),
                timeout: (int) config('openai.ipa.timeout', 60),
            );
        } catch (OpenAiException $e) {
            throw new VoiceoverException('Izgovor (IPA) nije mogao nastati: '.$e->getMessage(), 'ipa_'.$e->errorCode, retryable: $e->retryable, previous: $e);
        }

        [$accepted, $dropped] = $this->accept($result->data, $lines);

        $rows = [];

        foreach ($lines as $number => $line) {
            $rows[$hashes[$number]] = $this->keep($hashes[$number], $line, $accepted[$number], $model);
        }

        Log::info('hub.voiceover.ipa', [
            'lines' => count($lines),
            'marks' => array_sum(array_map('count', $accepted)),
            'dropped' => $dropped,
            'model' => $result->model,
            'tokens' => [$result->inputTokens, $result->outputTokens],
        ]);

        return $rows;
    }

    /**
     * What the model answered, less every transcription that is not what it says it is.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $lines
     * @return array{0: list<list<array{word: int, ipa: string}>>, 1: int} The marks of each line and how many were dropped.
     */
    private function accept(array $data, array $lines): array
    {
        $accepted = array_fill(0, count($lines), []);
        $seen = [];
        $dropped = 0;

        foreach ((array) ($data['marks'] ?? []) as $mark) {
            $line = is_array($mark) ? ($mark['line'] ?? null) : null;
            $word = is_array($mark) ? ($mark['word'] ?? null) : null;
            $ipa = is_array($mark) ? ($mark['ipa'] ?? null) : null;

            $text = is_int($line) && is_int($word) ? (Ipa::words($lines[$line] ?? '')[$word]['text'] ?? null) : null;
            $clean = $text !== null && is_string($ipa) && Ipa::markable($text) ? Ipa::clean($text, $ipa) : null;

            if ($clean === null || isset($seen[$line][$word])) {
                $dropped++;

                continue;
            }

            $seen[$line][$word] = true;
            $accepted[$line][] = ['word' => $word, 'ipa' => $clean];
        }

        if ($dropped > 0) {
            Log::warning('hub.voiceover.ipa_dropped', ['dropped' => $dropped, 'answer' => $data]);
        }

        return [$accepted, $dropped];
    }

    /**
     * @param  list<array{word: int, ipa: string}>  $marks
     */
    private function keep(string $hash, string $line, array $marks, string $model): VoiceoverTranscription
    {
        try {
            return VoiceoverTranscription::query()->firstOrCreate(['hash' => $hash], ['text' => $line, 'marks' => $marks, 'model' => $model]);
        } catch (UniqueConstraintViolationException) {
            // Another render transcribed the same line a moment ago; its answer is the one every video keeps.
            return VoiceoverTranscription::query()->where('hash', $hash)->firstOrFail();
        }
    }

    private function hash(string $line, string $model): string
    {
        return hash('sha256', json_encode([self::VERSION, $model, $line], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function model(): string
    {
        return (string) (config('openai.ipa.model') ?: config('openai.model'));
    }
}
