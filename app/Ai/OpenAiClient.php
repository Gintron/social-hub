<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * OpenAI, through the one call the hub needs: a question whose answer must fit a JSON schema.
 *
 * It speaks the Responses API. With `strict` a schema is enforced while the model writes, so an answer
 * either fits or is a refusal or was cut short — each of which is told apart here, because each costs
 * a caller something different. The request goes out with `store: false`: what the hub asks is its own
 * drafts and scripts, and there is no reason for the answer to stay on OpenAI's side.
 *
 * @see https://developers.openai.com/api/docs/guides/structured-outputs
 */
final class OpenAiClient
{
    /** What a page waits for the API before it shows what it has. */
    private const PAGE_TIMEOUT_SECONDS = 8;

    public function configured(): bool
    {
        return filled(config('openai.api_key'));
    }

    /**
     * @param  array<string, mixed>  $schema  A strict JSON schema: every property required, none beyond them.
     * @param  string|null  $effort  How much the model thinks first (none … max, as the model offers); null sends nothing.
     *
     * @throws OpenAiException
     */
    public function structured(
        string $model,
        string $system,
        string $user,
        string $name,
        array $schema,
        ?string $effort = null,
        ?int $maxOutputTokens = null,
        ?int $timeout = null,
    ): OpenAiResult {
        $body = [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => $name, 'strict' => true, 'schema' => $schema]],
            'store' => false,
        ];

        if (filled($effort)) {
            $body['reasoning'] = ['effort' => $effort];
        }

        if ($maxOutputTokens !== null) {
            $body['max_output_tokens'] = $maxOutputTokens;
        }

        $response = $this->send(fn (): Response => $this->request(timeout: $timeout)->post('/responses', $body));

        if ($response->failed()) {
            throw $this->error($response);
        }

        return $this->answer($response, $model);
    }

    /**
     * Whether the key can use this model — the doctor's question. Free, and a wrong name or a key without
     * access to the model answers here instead of on the first caption.
     *
     * @return array<string, mixed>
     *
     * @throws OpenAiException
     */
    public function model(string $id): array
    {
        $response = $this->send(fn (): Response => $this->request(patient: false)->get('/models/'.rawurlencode($id)));

        if ($response->failed()) {
            throw $this->error($response);
        }

        return (array) $response->json();
    }

    /**
     * @throws OpenAiException
     */
    private function answer(Response $response, string $model): OpenAiResult
    {
        $status = (string) $response->json('status', 'completed');

        if ($status === 'incomplete') {
            $reason = (string) $response->json('incomplete_details.reason', 'unknown');

            throw new OpenAiException("OpenAI je prekinuo odgovor ({$reason})".($reason === 'max_output_tokens' ? ': model je potrošio sve znakove na razmišljanje.' : '.'), 'incomplete');
        }

        if ($status === 'failed') {
            throw new OpenAiException('OpenAI nije uspio odgovoriti: '.mb_substr((string) $response->json('error.message', 'nepoznata greška'), 0, 300), 'unavailable', retryable: true);
        }

        $text = '';

        foreach ((array) $response->json('output', []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (($part['type'] ?? null) === 'refusal') {
                    throw new OpenAiException('OpenAI je odbio zahtjev: '.mb_substr((string) ($part['refusal'] ?? ''), 0, 300), 'refused');
                }

                if (($part['type'] ?? null) === 'output_text') {
                    $text .= (string) ($part['text'] ?? '');
                }
            }
        }

        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OpenAiException('OpenAI nije vratio JSON prema shemi.', 'invalid_output', retryable: true, previous: $e);
        }

        if (! is_array($data)) {
            throw new OpenAiException('OpenAI nije vratio JSON prema shemi.', 'invalid_output', retryable: true);
        }

        return new OpenAiResult(
            data: $data,
            model: (string) $response->json('model', $model),
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
        );
    }

    /**
     * A request that never got an answer (no route, a timeout) is the API being unavailable, the same as a
     * 5xx: the caller sees one kind of failure, whichever way the API was out of reach.
     *
     * @param  callable(): Response  $request
     *
     * @throws OpenAiException
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new OpenAiException('OpenAI nije dostupan: '.mb_substr($e->getMessage(), 0, 200), 'unavailable', retryable: true, previous: $e);
        }
    }

    /**
     * @param  bool  $patient  A render can wait for the API (a minute, three tries); a person looking at a
     *                         page cannot (a few seconds, one try).
     *
     * @throws OpenAiException
     */
    private function request(bool $patient = true, ?int $timeout = null): PendingRequest
    {
        $key = (string) config('openai.api_key');

        if ($key === '') {
            throw new OpenAiException('OPENAI_API_KEY nije postavljen.', 'not_configured');
        }

        $request = Http::baseUrl(mb_rtrim((string) config('openai.base_url', 'https://api.openai.com/v1'), '/'))
            ->withToken($key)
            ->acceptJson();

        if (! $patient) {
            return $request->timeout(self::PAGE_TIMEOUT_SECONDS);
        }

        // Busy moments are answered with 429 or a 5xx: wait about twenty seconds rather than lose the answer.
        // A used-up balance is also a 429, and no amount of waiting fills it.
        return $request
            ->timeout($timeout ?? (int) config('openai.timeout', 90))
            ->retry([1500, 5000, 12000], when: fn (Throwable $e): bool => $this->transient($e), throw: false);
    }

    private function transient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return ($status === 429 && ! $this->outOfCredit($e->response)) || $status >= 500;
        }

        return false;
    }

    private function outOfCredit(Response $response): bool
    {
        return $response->json('error.code') === 'insufficient_quota' || $response->json('error.type') === 'insufficient_quota';
    }

    private function error(Response $response): OpenAiException
    {
        $code = (string) $response->json('error.code', '');
        $message = $response->json('error.message');
        $summary = "OpenAI {$response->status()}".($code !== '' ? " ({$code})" : '').': '
            .mb_substr(is_string($message) ? $message : $response->body(), 0, 300);

        return match (true) {
            $this->outOfCredit($response) => new OpenAiException($summary.' — na OpenAI računu nema kredita.', 'quota_exceeded'),
            $response->status() === 401 || $code === 'invalid_api_key' => new OpenAiException($summary, 'invalid_key'),
            $response->status() === 403 => new OpenAiException($summary, 'forbidden'),
            $code === 'model_not_found' || $response->status() === 404 => new OpenAiException($summary.' — provjeri ime modela u postavkama.', 'model_not_found'),
            $response->status() === 429 => new OpenAiException($summary, 'rate_limited', retryable: true),
            in_array($response->status(), [400, 422], true) => new OpenAiException($summary, 'rejected'),
            $response->serverError() => new OpenAiException($summary, 'unavailable', retryable: true),
            default => new OpenAiException($summary, 'error'),
        };
    }
}
