<?php

declare(strict_types=1);

namespace App\Persona;

use InvalidArgumentException;

/**
 * The script of one short: what the character says, line by line, and what is on screen while it is said.
 *
 * A line is `talking` (the character to camera, lips moving with the voice) or `broll` (a scene that illustrates the
 * line while the voice goes on). `visual` is what that shot shows, in words a video model can be given. The caption and
 * hashtags are what goes under the post; the title is for YouTube, which wants one.
 *
 * It is stored as `topic_ideas.script` and a person edits it in the panel, so `fromArray()` is the gate for the model's
 * answer and for a person's edit alike.
 */
final readonly class ShortScript
{
    public const SHOTS = ['talking' => 'Lik govori u kameru', 'broll' => 'Međukadar'];

    public const MAX_LINES = 16;

    /**
     * @param  list<array{text: string, shot: string, visual: string}>  $lines
     */
    public function __construct(
        public string $title,
        public array $lines,
        public string $caption,
        public string $hashtags,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException When there is nothing a short can be made of.
     */
    public static function fromArray(array $data): self
    {
        $lines = [];

        foreach (array_values((array) ($data['lines'] ?? [])) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $text = mb_trim((string) ($row['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $shot = (string) ($row['shot'] ?? '');

            $lines[] = [
                'text' => $text,
                'shot' => array_key_exists($shot, self::SHOTS) ? $shot : 'talking',
                'visual' => mb_trim((string) ($row['visual'] ?? '')),
            ];
        }

        if ($lines === []) {
            throw new InvalidArgumentException('Skripta nema nijedan redak teksta.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException('Skripta ima više od '.self::MAX_LINES.' redaka; short to ne nosi.');
        }

        $title = mb_trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            throw new InvalidArgumentException('Skripta nema naslov.');
        }

        return new self(
            title: mb_substr($title, 0, 100),
            lines: $lines,
            caption: mb_trim((string) ($data['caption'] ?? '')),
            hashtags: mb_trim((string) ($data['hashtags'] ?? '')),
        );
    }

    /**
     * The schema the model writes the script to: every field required and nothing else (OpenAI strict mode).
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Video title for YouTube Shorts, at most 90 characters, no hashtags.'],
                'lines' => [
                    'type' => 'array',
                    'description' => 'The spoken script in order, one sentence or short phrase per line.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string', 'description' => 'Exactly what the character says. Spoken words only: no stage directions, emojis or hashtags.'],
                            'shot' => ['type' => 'string', 'enum' => array_keys(self::SHOTS), 'description' => 'talking: the character speaks to camera. broll: a scene illustrates the line while the voice continues.'],
                            'visual' => ['type' => 'string', 'description' => 'What the shot shows, as a short prompt for a video model. For talking: the gesture or expression. For broll: the scene, without text, logos or real people.'],
                        ],
                        'required' => ['text', 'shot', 'visual'],
                        'additionalProperties' => false,
                    ],
                ],
                'caption' => ['type' => 'string', 'description' => 'The text under the post: a hook line, two or three short lines, no links, no hashtags.'],
                'hashtags' => ['type' => 'string', 'description' => 'Three to five hashtags separated by spaces, lowercase, each starting with #.'],
            ],
            'required' => ['title', 'lines', 'caption', 'hashtags'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{title: string, lines: list<array{text: string, shot: string, visual: string}>, caption: string, hashtags: string}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'lines' => $this->lines, 'caption' => $this->caption, 'hashtags' => $this->hashtags];
    }

    public function spokenText(): string
    {
        return implode(' ', array_column($this->lines, 'text'));
    }

    /**
     * How long the voice takes to say it, roughly: words at the language's speaking pace. Good enough to tell a
     * 40-second short from a two-minute one before anything is paid for.
     */
    public function estimatedSeconds(string $language): int
    {
        $words = count(preg_split('/\s+/u', $this->spokenText(), flags: PREG_SPLIT_NO_EMPTY) ?: []);
        $pace = (float) config("hub.persona.words_per_second.{$language}", 2.5);

        return (int) ceil($words / max($pace, 0.5));
    }
}
