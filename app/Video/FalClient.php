<?php

declare(strict_types=1);

namespace App\Video;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * fal's queue: a request is submitted, asked about and its result fetched — separate calls, because a video takes minutes.
 *
 * What submit() returns is what a caller keeps: with it, a later try asks about the same request instead of paying for a
 * new one. Files go in as data URIs (fal decodes them), so nothing of the hub has to be reachable from outside.
 *
 * @see https://docs.fal.ai/model-apis/model-endpoints/queue
 */
final class FalClient
{
    public static function dataUri(string $path, string $mime): string
    {
        return "data:{$mime};base64,".base64_encode((string) file_get_contents($path));
    }

    public function configured(): bool
    {
        return filled(config('fal.key'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{request_id: string, status_url: string, response_url: string}
     *
     * @throws FalException
     */
    public function submit(string $model, array $input): array
    {
        $response = $this->send(fn (): Response => $this->request()->post(mb_rtrim((string) config('fal.queue_url'), '/').'/'.$model, $input));

        if ($response->failed()) {
            throw $this->error($response, "fal ({$model}) nije primio zahtjev");
        }

        $id = (string) $response->json('request_id', '');
        $status = (string) $response->json('status_url', '');
        $result = (string) $response->json('response_url', '');

        if ($id === '' || $status === '' || $result === '') {
            throw new FalException("fal ({$model}) nije vratio broj zahtjeva.", 'error', retryable: true);
        }

        return ['request_id' => $id, 'status_url' => $status, 'response_url' => $result];
    }

    /**
     * A request answered while it is waited for — for what takes seconds (a picture), where a person is watching the
     * page. Video goes through submit() and check().
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws FalException
     */
    public function run(string $model, array $input, int $timeout = 120): array
    {
        $response = $this->send(fn (): Response => $this->request()->timeout($timeout)->post(mb_rtrim((string) config('fal.run_url', 'https://fal.run'), '/').'/'.$model, $input));

        if ($response->failed()) {
            throw $this->error($response, "fal ({$model}) nije napravio sliku");
        }

        return (array) $response->json();
    }

    /**
     * Asks once whether a submitted request is done: its result (the model's output, e.g. `video.url`) when it is, null
     * while fal is still at it. Waiting is the caller's — a queued job comes back later rather than holding the worker.
     *
     * @param  array{status_url: string, response_url: string}  $request
     * @return array<string, mixed>|null
     *
     * @throws FalException
     */
    public function check(array $request): ?array
    {
        $response = $this->send(fn (): Response => $this->request()->get($request['status_url']));

        if ($response->failed()) {
            throw $this->error($response, 'fal nije odgovorio o zahtjevu');
        }

        if ($response->json('status') !== 'COMPLETED') {
            return null;
        }

        $result = $this->send(fn (): Response => $this->request()->get($request['response_url']));

        // A completed request whose result is an error is a request that failed at fal: asking about it again changes
        // nothing, so it is never retryable (a person asks for the video again, which is a new request).
        if ($result->failed()) {
            $error = $this->error($result, 'fal nije napravio video');

            throw $result->status() === 429
                ? $error
                : new FalException($error->getMessage(), $error->errorCode === 'invalid_key' ? 'invalid_key' : 'failed');
        }

        return (array) $result->json();
    }

    /**
     * @throws FalException
     */
    public function download(string $url, string $path): void
    {
        // A shot is a few megabytes; held in memory, it is written only when it arrived whole.
        $response = $this->send(fn (): Response => Http::timeout(300)->get($url));

        if ($response->failed() || $response->body() === '' || file_put_contents($path, $response->body()) === false) {
            throw new FalException("Video s fala se nije mogao preuzeti (HTTP {$response->status()}).", 'unavailable', retryable: true);
        }
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws FalException
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new FalException('fal nije dostupan: '.mb_substr($e->getMessage(), 0, 200), 'unavailable', retryable: true, previous: $e);
        }
    }

    /**
     * @throws FalException
     */
    private function request(): PendingRequest
    {
        $key = (string) config('fal.key');

        if ($key === '') {
            throw new FalException('FAL_KEY nije postavljen.', 'not_configured');
        }

        return Http::withHeaders(['Authorization' => "Key {$key}"])
            ->acceptJson()
            ->timeout((int) config('fal.timeout', 60))
            ->retry([2000, 6000], when: fn ($e): bool => $e instanceof ConnectionException, throw: false);
    }

    private function error(Response $response, string $what): FalException
    {
        $detail = $response->json('detail');
        $message = is_string($detail) ? $detail : (is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : $response->body());
        $summary = "{$what} (HTTP {$response->status()}): ".mb_substr((string) $message, 0, 300);

        return match (true) {
            in_array($response->status(), [401, 403], true) => new FalException($summary, 'invalid_key'),
            in_array($response->status(), [400, 422], true) => new FalException($summary, 'rejected'),
            $response->status() === 429, $response->serverError() => new FalException($summary, 'unavailable', retryable: true),
            default => new FalException($summary, 'failed'),
        };
    }
}
