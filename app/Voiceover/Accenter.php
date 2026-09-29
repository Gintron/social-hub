<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Ai\OpenAiClient;
use App\Ai\OpenAiException;
use App\Models\VoiceoverAccent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Where the stress falls in what a voice is about to say.
 *
 * A voice that reads Croatian gets a rare word, a name or a word that is spelled like another wrong now
 * and then, and no amount of listening makes that a rule the hub can write. A model that knows the language
 * does. It is asked about the words of a line — never to rewrite the line — and each answer is checked by
 * code (Stress::position) before it is believed: the word must be the same word with one mark on one
 * vowel, otherwise that mark is dropped and the word is spoken as it was. The model can add a mark; it
 * cannot change what is said.
 *
 * What it answers is kept (`voiceover_accents`), so a line is marked once: the same text goes to the voice
 * in every video, and a clip already paid for is found again.
 */
final class Accenter
{
    /**
     * Raise it when the instructions or the way an answer is read change: lines are then marked again.
     */
    private const VERSION = 1;

    private const INSTRUCTIONS = <<<'TXT'
Ti si lektor hrvatskoga standardnog jezika. Rečenice koje dobiješ naglas čita sintetizator govora, a tvoj je zadatak označiti gdje pada naglasak u riječima koje bi on mogao pročitati krivo.

Svaka je rečenica već raspisana riječima: brojevi, iznosi i datumi su izgovoreni, pa ih ne diraš. Uz rečenicu dobiješ popis riječi koje smiješ označiti, svaku s rednim brojem u rečenici (word, od 0). Rečenice imaju redni broj (line, od 0).

Za svaku riječ koju treba označiti vrati broj rečenice (line), broj riječi (word) i istu tu riječ (marked) s akutom (´) na naglašenom samoglasniku: á, é, í, ó, ú. Ne mijenjaj nijedno slovo niti veličinu slova, samo dodaj akut. Jedan naglasak po riječi. Označi samo mjesto naglaska: ne razlikuj silazni od uzlaznog ni dugi od kratkog.

Što označiti:
- riječi koje se pišu jednako, a naglašavaju se različito ovisno o značenju ili obliku: naglasak odaberi prema smislu rečenice;
- vlastita imena (osobe, mjesta), nazive trgovina i marki te posuđenice;
- rjeđe riječi i oblike u kojima naglasak nije na prvom slogu, pa ga je lako pogrešno postaviti.

Što ne označiti: sve što nije na popisu, prijedloge, veznike i čestice te uobičajene riječi u kojima nema dvojbe.

Bolje je riječ izostaviti nego naglasiti krivo: ako nisi siguran, ne označuj je. Ako nijednu riječ ne treba označiti, vrati praznu listu.
TXT;

    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * The lines as the voice is to read them, with the stress told in `$style`. A line with nothing to mark
     * (or an empty one) comes back as it was; so does everything when the style is off, without a request.
     *
     * @param  list<string>  $lines  Spelled out, as SpokenCroatian leaves them.
     * @return list<string>
     *
     * @throws VoiceoverException When the marks cannot be had; a voice is not made without them.
     */
    public function prepare(array $lines, string $style): array
    {
        if ($style === Stress::OFF) {
            return $lines;
        }

        $lines = array_map(Stress::normalize(...), $lines);

        return array_map(
            fn (string $line, array $marks): string => Stress::render($line, $marks, $style),
            $lines,
            $this->marks($lines),
        );
    }

    /**
     * The stress of each line, from what was kept or — for the lines nobody has marked — from one request.
     *
     * @param  list<string>  $lines
     * @return list<list<array{word: int, at: int}>> The places of the marks, one list for each line.
     *
     * @throws VoiceoverException
     */
    public function marks(array $lines): array
    {
        $model = $this->model();
        $hashes = [];

        foreach ($lines as $index => $line) {
            if (array_any(Stress::words($line), fn (array $word): bool => Stress::markable($word['text']))) {
                $hashes[$index] = $this->hash($line, $model);
            }
        }

        $known = $hashes === []
            ? collect()
            : VoiceoverAccent::query()->whereIn('hash', array_values(array_unique($hashes)))->get()->keyBy('hash');

        $unknown = [];

        foreach ($hashes as $index => $hash) {
            if (! $known->has($hash)) {
                $unknown[$hash] = $lines[$index];
            }
        }

        if ($unknown !== []) {
            foreach ($this->ask($unknown, $model) as $hash => $accent) {
                $known->put($hash, $accent);
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
                    'description' => 'Riječi kojima treba označiti naglasak; prazna lista ako nijednoj.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'line' => ['type' => 'integer', 'description' => 'Broj rečenice (line).'],
                            'word' => ['type' => 'integer', 'description' => 'Broj riječi (word) u toj rečenici.'],
                            'marked' => ['type' => 'string', 'description' => 'Ista riječ, ista slova i ista veličina slova, s akutom na naglašenom samoglasniku.'],
                        ],
                        'required' => ['line', 'word', 'marked'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['marks'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, string>  $unknown  Line by hash.
     * @return array<string, VoiceoverAccent>
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

            foreach (Stress::words($line) as $index => $word) {
                if (Stress::markable($word['text'])) {
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
                name: 'stress_marks',
                schema: self::schema(),
                effort: config('openai.accents.effort') ?: config('openai.effort'),
                maxOutputTokens: (int) config('openai.accents.max_output_tokens', 12000),
                timeout: (int) config('openai.accents.timeout', 60),
            );
        } catch (OpenAiException $e) {
            throw new VoiceoverException('Naglasci nisu mogli nastati: '.$e->getMessage(), 'accents_'.$e->errorCode, retryable: $e->retryable, previous: $e);
        }

        [$accepted, $dropped] = $this->accept($result->data, $lines);

        $rows = [];

        foreach ($lines as $number => $line) {
            $rows[$hashes[$number]] = $this->keep($hashes[$number], $line, $accepted[$number], $model);
        }

        Log::info('hub.voiceover.accents', [
            'lines' => count($lines),
            'marks' => array_sum(array_map('count', $accepted)),
            'dropped' => $dropped,
            'model' => $result->model,
            'tokens' => [$result->inputTokens, $result->outputTokens],
        ]);

        return $rows;
    }

    /**
     * What the model answered, less every mark that is not what it says it is.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $lines
     * @return array{0: list<list<array{word: int, at: int}>>, 1: int} The marks of each line and how many were dropped.
     */
    private function accept(array $data, array $lines): array
    {
        $accepted = array_fill(0, count($lines), []);
        $seen = [];
        $dropped = 0;

        foreach ((array) ($data['marks'] ?? []) as $mark) {
            $line = is_array($mark) ? ($mark['line'] ?? null) : null;
            $word = is_array($mark) ? ($mark['word'] ?? null) : null;
            $marked = is_array($mark) ? ($mark['marked'] ?? null) : null;

            $text = is_int($line) && is_int($word) ? (Stress::words($lines[$line] ?? '')[$word]['text'] ?? null) : null;
            $at = $text !== null && is_string($marked) && Stress::markable($text) ? Stress::position($text, $marked) : null;

            if ($at === null || isset($seen[$line][$word])) {
                $dropped++;

                continue;
            }

            $seen[$line][$word] = true;
            $accepted[$line][] = ['word' => $word, 'at' => $at];
        }

        if ($dropped > 0) {
            Log::warning('hub.voiceover.accents_dropped', ['dropped' => $dropped, 'answer' => $data]);
        }

        return [$accepted, $dropped];
    }

    /**
     * @param  list<array{word: int, at: int}>  $marks
     */
    private function keep(string $hash, string $line, array $marks, string $model): VoiceoverAccent
    {
        try {
            return VoiceoverAccent::query()->firstOrCreate(['hash' => $hash], ['text' => $line, 'marks' => $marks, 'model' => $model]);
        } catch (UniqueConstraintViolationException) {
            // Another render marked the same line a moment ago; its answer is the one every video keeps.
            return VoiceoverAccent::query()->where('hash', $hash)->firstOrFail();
        }
    }

    private function hash(string $line, string $model): string
    {
        return hash('sha256', json_encode([self::VERSION, $model, $line], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function model(): string
    {
        return (string) (config('openai.accents.model') ?: config('openai.model'));
    }
}
