<?php

declare(strict_types=1);

namespace App\Persona;

use App\Ai\OpenAiClient;
use App\Ai\OpenAiException;
use Carbon\CarbonImmutable;

/**
 * A few things an AI character could make a short about today, from OpenAI, written from the character's description
 * and goal. It proposes; a person picks. The topics the character already had are shown to the model so it does not
 * offer them again, and an answer that repeats one anyway is dropped here rather than trusted to the prompt.
 */
final class TopicSuggester
{
    private const INSTRUCTIONS = <<<'TXT'
You plan short vertical videos (TikTok, Instagram Reels, YouTube Shorts; 30 to 60 seconds) for an AI character who runs their own channels. The character is described below. Propose the number of topics asked for, for the day given.

Each topic is one clear idea a viewer can use, understand or feel today:
- title: the idea in plain words, at most 80 characters.
- hook: the first sentence the character says, at most 15 words; it has to stop someone scrolling.
- angle: two or three sentences on what the video says and the one thing the viewer takes away.
- reason: one sentence on why it fits this channel, and today if the date matters.

Rules:
- Topics differ from each other and from the recent topics listed in the message.
- Stay inside the character's field and goal. Never a topic from "Never talk about".
- No news you cannot be sure of, no invented statistics, studies, quotes or prices.
- No medical, legal or investment advice a viewer could act on to their harm; general, careful guidance is fine.
- The character is openly an AI. Never a topic that needs them to have lived something ("I tried", "my kids", "when I was at school").
TXT;

    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * @param  list<string>  $recentTitles  Topics the character already had (proposed, picked or set aside).
     * @return array{topics: list<array{title: string, hook: string, angle: string, reason: string}>, model: string}
     *
     * @throws OpenAiException
     */
    public function suggest(Persona $persona, CarbonImmutable $day, array $recentTitles, int $count): array
    {
        $result = $this->client->structured(
            model: (string) (config('openai.persona.model') ?: config('openai.model')),
            system: self::INSTRUCTIONS."\n\nTHE CHARACTER\n".$persona->brief(),
            user: json_encode([
                'date' => $day->toDateString(),
                'weekday' => $day->locale('en')->dayName,
                'topics_wanted' => $count,
                'recent_topics' => array_values($recentTitles),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            name: 'topic_ideas',
            schema: self::schema(),
            effort: config('openai.persona.effort') ?: config('openai.effort'),
            maxOutputTokens: (int) config('openai.persona.max_output_tokens', 16000),
            timeout: (int) config('openai.persona.timeout', 120),
        );

        $seen = array_map(self::key(...), $recentTitles);
        $topics = [];

        foreach ((array) ($result->data['topics'] ?? []) as $topic) {
            if (! is_array($topic)) {
                continue;
            }

            $topic = array_map(fn (mixed $value): string => mb_trim(is_string($value) ? $value : ''), [
                'title' => $topic['title'] ?? '',
                'hook' => $topic['hook'] ?? '',
                'angle' => $topic['angle'] ?? '',
                'reason' => $topic['reason'] ?? '',
            ]);

            $key = self::key($topic['title']);

            if ($key === '' || $topic['hook'] === '' || $topic['angle'] === '' || in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $topics[] = [
                'title' => mb_substr($topic['title'], 0, 200),
                'hook' => mb_substr($topic['hook'], 0, 300),
                'angle' => $topic['angle'],
                'reason' => $topic['reason'],
            ];
        }

        return ['topics' => array_slice($topics, 0, $count), 'model' => $result->model];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $field = fn (string $description): array => ['type' => 'string', 'description' => $description];

        return [
            'type' => 'object',
            'properties' => [
                'topics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => $field('The idea in plain words, at most 80 characters.'),
                            'hook' => $field('The first sentence the character says, at most 15 words.'),
                            'angle' => $field('What the video says and the one takeaway, two or three sentences.'),
                            'reason' => $field('Why it fits this channel (and today), one sentence.'),
                        ],
                        'required' => ['title', 'hook', 'angle', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['topics'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Two titles are the same topic when they differ only in case, spacing and punctuation.
     */
    private static function key(string $title): string
    {
        return mb_trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)));
    }
}
