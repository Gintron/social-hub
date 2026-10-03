<?php

declare(strict_types=1);

namespace App\Voiceover;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ElevenLabs text-to-speech.
 *
 * Three things about the API shape this: the key travels in an `xi-api-key` header, a successful
 * speech request answers with the audio itself (not JSON), and a request that fails on the quota
 * still answers 401 with the reason in `detail.status` — so errors are told apart by that field
 * before the HTTP status.
 *
 * @see https://elevenlabs.io/docs/api-reference/text-to-speech/convert
 */
final class ElevenLabsClient
{
    /** Voices change rarely and the panel asks for the list whenever a brand form opens. */
    private const VOICES_CACHE_SECONDS = 900;

    /** What a page waits for the API before it shows what it has. */
    private const PAGE_TIMEOUT_SECONDS = 6;

    public function configured(): bool
    {
        return filled(config('elevenlabs.api_key'));
    }

    /**
     * @param  array<string, mixed>  $voiceSettings
     */
    public function speak(string $text, string $voiceId, string $model, array $voiceSettings = []): SpokenAudio
    {
        $format = (string) config('elevenlabs.output_format', 'mp3_44100_128');

        $response = $this->send(fn (): Response => $this->request()
            ->accept('audio/mpeg')
            ->post('/v1/text-to-speech/'.rawurlencode($voiceId).'?output_format='.rawurlencode($format), array_filter([
                'text' => $text,
                'model_id' => $model,
                'voice_settings' => $voiceSettings === [] ? null : $voiceSettings,
            ], fn (mixed $value): bool => $value !== null)));

        if ($response->failed()) {
            throw $this->error($response);
        }

        $audio = $response->body();

        // A real clip is kilobytes; anything smaller is an error page or an empty stream.
        if (mb_strlen($audio, '8bit') < 500) {
            throw new VoiceoverException('ElevenLabs je vratio prazan zvučni zapis.', 'empty_audio', retryable: true);
        }

        // The docs call this header "the cost of the generation in characters". Measured against the same 43-character
        // line it was 14 on multilingual v2, 9 on v3, 7 on flash v2.5 and 3 on v4 (2026-10-03): it is what the request
        // was billed in credits, so it says what a line cost but never how long it was. Length is the text's own.
        $cost = $response->header('character-cost');

        return new SpokenAudio(
            bytes: $audio,
            requestId: filled($response->header('request-id')) ? $response->header('request-id') : null,
            cost: is_numeric($cost) ? (int) $cost : null,
        );
    }

    /**
     * The account's voices as `voice_id => label`, those verified for Croatian first.
     *
     * @return array<string, string>
     */
    public function voices(bool $fresh = false): array
    {
        $key = 'elevenlabs.voices.'.hash('sha256', (string) config('elevenlabs.api_key'));

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::VOICES_CACHE_SECONDS, fn (): array => $this->fetchVoices());
    }

    /**
     * Characters used and allowed this period, for the doctor and the panel.
     *
     * @return array{character_count: int, character_limit: int, next_character_count_reset_unix: int|null, tier: string|null, status: string|null}
     */
    public function subscription(): array
    {
        $response = $this->send(fn (): Response => $this->request(patient: false)->acceptJson()->get('/v1/user/subscription'));

        if ($response->failed()) {
            throw $this->error($response);
        }

        return [
            'character_count' => (int) $response->json('character_count', 0),
            'character_limit' => (int) $response->json('character_limit', 0),
            'next_character_count_reset_unix' => is_numeric($response->json('next_character_count_reset_unix')) ? (int) $response->json('next_character_count_reset_unix') : null,
            'tier' => is_string($response->json('tier')) ? $response->json('tier') : null,
            'status' => is_string($response->json('status')) ? $response->json('status') : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fetchVoices(): array
    {
        $voices = [];
        $token = null;

        // A few pages of a hundred are far more voices than a brand picks from.
        for ($page = 0; $page < 5; $page++) {
            $response = $this->send(fn (): Response => $this->request(patient: false)->acceptJson()->get('/v2/voices', array_filter([
                'page_size' => 100,
                'sort' => 'name',
                'next_page_token' => $token,
            ])));

            if ($response->failed()) {
                throw $this->error($response);
            }

            foreach ((array) $response->json('voices', []) as $voice) {
                if (! is_array($voice) || blank($voice['voice_id'] ?? null)) {
                    continue;
                }

                $voices[(string) $voice['voice_id']] = [$this->croatian($voice), $this->label($voice)];
            }

            $token = $response->json('next_page_token');

            if (! $response->json('has_more') || ! is_string($token) || $token === '') {
                break;
            }
        }

        uasort($voices, fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(fn (array $voice): string => $voice[1], $voices);
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function croatian(array $voice): bool
    {
        foreach ((array) ($voice['verified_languages'] ?? []) as $language) {
            $code = mb_strtolower((string) (is_array($language) ? ($language['language'] ?? '') : $language));

            if (in_array($code, ['hr', 'hrv', 'croatian', 'hrvatski'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function label(array $voice): string
    {
        $labels = (array) ($voice['labels'] ?? []);
        $details = array_filter([
            $voice['category'] ?? null,
            $labels['gender'] ?? null,
            $labels['accent'] ?? null,
            $labels['descriptive'] ?? ($labels['description'] ?? null),
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        return ($this->croatian($voice) ? '★ ' : '').(string) ($voice['name'] ?? $voice['voice_id'])
            .($details === [] ? '' : ' ('.implode(', ', $details).')');
    }

    /**
     * A request that never got an answer (no route, a timeout) is the API being unavailable, the same as
     * a 5xx: the caller sees one kind of failure, whichever way the API was out of reach.
     *
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new VoiceoverException('ElevenLabs nije dostupan: '.mb_substr($e->getMessage(), 0, 200), 'unavailable', retryable: true, previous: $e);
        }
    }

    /**
     * @param  bool  $patient  A render can wait for the API (a minute, three tries); a person looking at a
     *                         page cannot (a few seconds, one try).
     */
    private function request(bool $patient = true): PendingRequest
    {
        $key = (string) config('elevenlabs.api_key');

        if ($key === '') {
            throw new VoiceoverException('ELEVENLABS_API_KEY nije postavljen.', 'not_configured');
        }

        $request = Http::baseUrl(mb_rtrim((string) config('elevenlabs.base_url', 'https://api.elevenlabs.io'), '/'))
            ->withHeaders(['xi-api-key' => $key]);

        if (! $patient) {
            return $request->timeout(self::PAGE_TIMEOUT_SECONDS);
        }

        // A busy account answers 429 while other requests finish, and the API has 5xx moments: wait
        // up to about twenty seconds rather than lose a video's voice. Anything else is a fact about the request.
        return $request
            ->timeout((int) config('elevenlabs.http_timeout', 60))
            ->retry([2000, 6000, 15000], when: fn (Throwable $e): bool => $this->transient($e), throw: false);
    }

    private function transient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    private function error(Response $response): VoiceoverException
    {
        $status = (string) $response->json('detail.status', '');
        $detail = $response->json('detail');
        $message = match (true) {
            is_array($detail) && isset($detail['message']) => (string) $detail['message'],
            is_string($detail) => $detail,
            is_array($detail) && isset($detail[0]['msg']) => (string) $detail[0]['msg'],
            default => mb_substr($response->body(), 0, 300),
        };

        $summary = "ElevenLabs {$response->status()}".($status !== '' ? " ({$status})" : '').': '.mb_substr($message, 0, 300);

        return match (true) {
            $status === 'quota_exceeded' => new VoiceoverException($summary.' — nema dovoljno znakova u planu.', 'quota_exceeded'),
            $status === 'missing_permissions' => new VoiceoverException($summary.' — ključ nema to pravo.', 'missing_permissions'),
            in_array($status, ['invalid_api_key', 'needs_authorization'], true) || $response->status() === 401 => new VoiceoverException($summary, 'invalid_key'),
            $status === 'voice_not_found' || $response->status() === 404 => new VoiceoverException($summary.' — provjeri ID glasa u postavkama brenda.', 'voice_not_found'),
            $response->status() === 429 => new VoiceoverException($summary, 'rate_limited', retryable: true),
            $response->status() === 422 => new VoiceoverException($summary, 'rejected'),
            $response->serverError() => new VoiceoverException($summary, 'unavailable', retryable: true),
            default => new VoiceoverException($summary, 'error'),
        };
    }
}
