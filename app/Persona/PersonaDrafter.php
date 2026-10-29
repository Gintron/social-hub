<?php

declare(strict_types=1);

namespace App\Persona;

use App\Ai\OpenAiClient;
use App\Ai\OpenAiException;

/**
 * A character from an idea of a line or two: OpenAI writes the fields of Brendovi → AI lik (who, what for, for whom,
 * how they sound, what they never talk about, how they look, where they stand, what their voice is like). It fills
 * the form; nothing is kept until a person reads it and saves.
 */
final class PersonaDrafter
{
    public const FIELDS = [
        'name' => 'A first name for the character, easy to say in the channel\'s language.',
        'description' => 'Who the character is, in two or three sentences: age, what they know, how they come across.',
        'goal' => 'What the channel is for, for the viewer, in one or two sentences.',
        'audience' => 'Who watches, in a few words.',
        'tone' => 'How the character talks, in a few words.',
        'avoid' => 'Topics and claims the character never makes, comma-separated; always include the risky ones of this field.',
        'appearance' => 'How the character looks, for a photorealistic portrait: age, hair, face, clothes. A fictional person, no celebrity likeness.',
        'ambience' => 'Where the character is filmed, one place that fits the field: room, light, a few objects.',
        'voice_description' => 'The character\'s voice for a voice designer: gender, age, accent, pace, warmth, energy.',
    ];

    private const INSTRUCTIONS = <<<'TXT'
You create an AI character who will run short-video channels (TikTok, Reels, YouTube Shorts) in one field. From the person's idea, write the character's profile. The character is openly an AI and fictional: never base them on a real or famous person, never give them a life story they would have to claim as real. Keep every field short and concrete. Write the fields in the language asked for, except appearance, ambience and voice_description, which are always in English (they go to image and voice models).
TXT;

    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * @return array<string, string> The persona fields, keyed as in `brands.persona` (plus voice_description).
     *
     * @throws OpenAiException
     */
    public function draft(string $idea, string $language): array
    {
        $properties = array_map(fn (string $description): array => ['type' => 'string', 'description' => $description], self::FIELDS);

        $result = $this->client->structured(
            model: (string) (config('openai.persona.model') ?: config('openai.model')),
            system: self::INSTRUCTIONS,
            user: json_encode(['idea' => mb_trim($idea), 'language' => Persona::LANGUAGES[$language] ?? 'English'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            name: 'persona_profile',
            schema: [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ],
            effort: config('openai.persona.effort') ?: config('openai.effort'),
            maxOutputTokens: (int) config('openai.persona.max_output_tokens', 16000),
            timeout: (int) config('openai.persona.timeout', 120),
        );

        $fields = [];

        foreach (array_keys(self::FIELDS) as $field) {
            $fields[$field] = mb_trim(is_string($result->data[$field] ?? null) ? $result->data[$field] : '');
        }

        return $fields;
    }
}
