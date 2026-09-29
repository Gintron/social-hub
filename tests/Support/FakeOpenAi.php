<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * What OpenAI's Responses API answers when a request asks for a JSON schema: the message with the JSON as text,
 * after the reasoning item a model that thinks first lists before it. Tests wrap this in `Http::response()`.
 */
final class FakeOpenAi
{
    /**
     * @param  array<string, mixed>  $data  What the model "wrote": the JSON the schema asked for.
     * @return array<string, mixed>
     */
    public static function answer(array $data, string $model = 'gpt-6-sol-2026-09-01'): array
    {
        return [
            'id' => 'resp_1',
            'object' => 'response',
            'status' => 'completed',
            'model' => $model,
            'output' => [
                // A model that thinks first lists that before the message; it is not the answer.
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed', 'content' => [
                    ['type' => 'output_text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE), 'annotations' => []],
                ]],
            ],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 45, 'total_tokens' => 165],
        ];
    }
}
