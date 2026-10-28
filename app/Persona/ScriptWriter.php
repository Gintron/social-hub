<?php

declare(strict_types=1);

namespace App\Persona;

use App\Ai\OpenAiClient;
use App\Ai\OpenAiException;
use App\Models\TopicIdea;
use InvalidArgumentException;

/**
 * The script of the short a person picked, from OpenAI: what the character says line by line and what is on screen
 * while it is said. It is a draft for a person to read and change before anything is paid for — the voice and the
 * video come only from an approved script.
 */
final class ScriptWriter
{
    private const INSTRUCTIONS = <<<'TXT'
You write the script of one short vertical video (TikTok, Instagram Reels, YouTube Shorts) for an AI character who runs their own channels. The character and the topic are described below. The character's voice says the lines; a video shows either the character talking to camera or a scene that illustrates the line.

The script:
- The first line is the hook: it stops someone scrolling within two seconds. Start from the topic's hook; you may sharpen it.
- One sentence or short phrase per line, said the way people talk, not the way they write.
- Start and end with "talking". Use "broll" for at most half of the lines, where showing helps more than a face.
- The last line asks for a reaction (a question to answer in the comments, or to follow for more). Never a link.
- Stay under the spoken length given in the message.
- For "talking", visual is the gesture or expression. For "broll", visual is a scene a video model can make: what is in it, light, camera; no text on screen, no logos, no real or famous people.

Rules:
- Stay inside the character's field and goal; never anything from "Never talk about".
- No invented statistics, studies, quotes, prices or news. If a number is not common knowledge, say it without one.
- No medical, legal or investment advice a viewer could act on to their harm.
- The character is openly an AI. They never claim to have lived something ("I tried", "my kids", "last week I").
- The caption repeats nothing the script does not say: no numbers, promises or claims of its own, and no links.
TXT;

    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * @return array{script: ShortScript, model: string}
     *
     * @throws OpenAiException
     * @throws InvalidArgumentException When the answer has nothing a short can be made of.
     */
    public function write(TopicIdea $idea, Persona $persona): array
    {
        $seconds = (int) config('hub.persona.max_seconds', 60);
        $pace = (float) config("hub.persona.words_per_second.{$persona->language}", 2.5);

        $result = $this->client->structured(
            model: (string) (config('openai.persona.model') ?: config('openai.model')),
            system: self::INSTRUCTIONS."\n\nTHE CHARACTER\n".$persona->brief(),
            user: json_encode([
                'topic' => $idea->title,
                'hook' => $idea->hook,
                'angle' => $idea->angle,
                'max_spoken_seconds' => $seconds,
                'max_spoken_words' => (int) floor($seconds * $pace),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            name: 'short_script',
            schema: ShortScript::schema(),
            effort: config('openai.persona.effort') ?: config('openai.effort'),
            maxOutputTokens: (int) config('openai.persona.max_output_tokens', 16000),
            timeout: (int) config('openai.persona.timeout', 120),
        );

        return ['script' => ShortScript::fromArray($result->data), 'model' => $result->model];
    }
}
