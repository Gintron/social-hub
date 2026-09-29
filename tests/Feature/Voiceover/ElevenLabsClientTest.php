<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Voiceover\ElevenLabsClient;
use App\Voiceover\VoiceoverException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSpeech;
use Tests\TestCase;

/**
 * What the hub sends to ElevenLabs and how it reads the answers. The API is faked; the shape of the
 * request is the documented one.
 */
final class ElevenLabsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('elevenlabs.base_url', 'https://api.elevenlabs.io');
        Sleep::fake();
        Cache::flush();
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string, bool}>
     */
    public static function failures(): array
    {
        return [
            'a wrong key' => [401, ['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key']], 'invalid_key', false],
            'a key without the permission' => [401, ['detail' => ['status' => 'missing_permissions', 'message' => 'The API key is missing the permission text_to_speech']], 'missing_permissions', false],
            // The quota answers 401 too, and it is not the key that is wrong.
            'the quota is used up' => [401, ['detail' => ['status' => 'quota_exceeded', 'message' => 'This request exceeds your quota of 10000.']], 'quota_exceeded', false],
            'a voice that is not there' => [404, ['detail' => ['status' => 'voice_not_found', 'message' => 'A voice with the voice id x does not exist.']], 'voice_not_found', false],
            'a rejected request' => [422, ['detail' => [['loc' => ['body', 'text'], 'msg' => 'Text is too long', 'type' => 'value_error']]], 'rejected', false],
        ];
    }

    public function test_it_asks_the_voice_to_say_the_text_with_the_key_in_a_header(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response(FakeSpeech::mp3(), 200, ['request-id' => 'req-1', 'character-cost' => '31'])]);

        $audio = app(ElevenLabsClient::class)->speak('Kruh za jedan euro.', 'voice-1', 'eleven_multilingual_v2', ['stability' => 0.55, 'speed' => 1.0]);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/voice-1?output_format=mp3_44100_128'
                && $request->hasHeader('xi-api-key', 'test-key')
                && $request->data() === [
                    'text' => 'Kruh za jedan euro.',
                    'model_id' => 'eleven_multilingual_v2',
                    'voice_settings' => ['stability' => 0.55, 'speed' => 1.0],
                ];
        });
        $this->assertGreaterThan(1000, mb_strlen($audio->bytes, '8bit'));
        $this->assertSame('req-1', $audio->requestId);
        $this->assertSame(31, $audio->characters, 'billed characters come from the character-cost header');
    }

    public function test_the_length_of_the_text_stands_in_when_the_api_reports_no_cost(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response(FakeSpeech::mp3(), 200)]);

        $audio = app(ElevenLabsClient::class)->speak('Pet znakova', 'voice-1', 'eleven_multilingual_v2');

        $this->assertSame(11, $audio->characters);
        $this->assertNull($audio->requestId);
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config()->set('elevenlabs.api_key', null);
        Http::fake();

        try {
            app(ElevenLabsClient::class)->speak('Bok', 'voice-1', 'eleven_multilingual_v2');
            $this->fail('A missing key must not reach the API.');
        } catch (VoiceoverException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }

        Http::assertNothingSent();
        $this->assertFalse(app(ElevenLabsClient::class)->configured());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('failures')]
    public function test_a_refusal_says_why_and_is_not_retried(int $status, array $body, string $code, bool $retryable): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response($body, $status)]);

        try {
            app(ElevenLabsClient::class)->speak('Bok', 'voice-1', 'eleven_multilingual_v2');
            $this->fail('The refusal should have been raised.');
        } catch (VoiceoverException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($retryable, $e->retryable);
        }

        Http::assertSentCount(1);
    }

    public function test_a_busy_account_is_waited_for_and_then_served(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::sequence()
            ->push(['detail' => ['status' => 'too_many_concurrent_requests', 'message' => 'Too many']], 429)
            ->push(FakeSpeech::mp3(), 200, ['request-id' => 'req-2'])]);

        $audio = app(ElevenLabsClient::class)->speak('Bok', 'voice-1', 'eleven_multilingual_v2');

        $this->assertSame('req-2', $audio->requestId);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_server_that_keeps_failing_is_given_up_on_after_four_tries(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response('Bad gateway', 502)]);

        try {
            app(ElevenLabsClient::class)->speak('Bok', 'voice-1', 'eleven_multilingual_v2');
            $this->fail('An API that never recovers must surface.');
        } catch (VoiceoverException $e) {
            $this->assertSame('unavailable', $e->errorCode);
            $this->assertTrue($e->retryable);
        }

        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
    }

    public function test_an_api_that_cannot_be_reached_is_unavailable_like_one_that_answers_with_a_5xx(): void
    {
        $attempts = 0;
        Http::fake(['api.elevenlabs.io/*' => function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        try {
            app(ElevenLabsClient::class)->speak('Bok', 'voice-1', 'eleven_multilingual_v2');
            $this->fail('A timeout must surface as a VoiceoverException, never as a transport exception.');
        } catch (VoiceoverException $e) {
            $this->assertSame('unavailable', $e->errorCode);
            $this->assertTrue($e->retryable);
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        $this->assertSame(4, $attempts, 'it waits and tries again before giving up');
    }

    public function test_an_empty_answer_is_an_error_not_a_silent_clip(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response('', 200)]);

        $this->expectException(VoiceoverException::class);
        $this->expectExceptionMessage('prazan');

        app(ElevenLabsClient::class)->speak('Bok', 'voice-1', 'eleven_multilingual_v2');
    }

    public function test_voices_come_paged_with_those_verified_for_croatian_first_and_are_cached(): void
    {
        Http::fake(['api.elevenlabs.io/v2/voices*' => Http::sequence()
            ->push([
                'voices' => [
                    ['voice_id' => 'v-b', 'name' => 'Bruno', 'category' => 'premade', 'labels' => ['gender' => 'male', 'accent' => 'american'], 'verified_languages' => []],
                    ['voice_id' => 'v-a', 'name' => 'Ana', 'category' => 'professional', 'labels' => ['gender' => 'female'], 'verified_languages' => [['language' => 'hr', 'model_id' => 'eleven_multilingual_v2']]],
                ],
                'has_more' => true,
                'next_page_token' => 'page-2',
            ])
            ->push([
                'voices' => [['voice_id' => 'v-c', 'name' => 'Cvijeta', 'category' => 'cloned', 'labels' => [], 'verified_languages' => []]],
                'has_more' => false,
            ])]);

        $voices = app(ElevenLabsClient::class)->voices();

        $this->assertSame(['v-a', 'v-b', 'v-c'], array_keys($voices));
        $this->assertSame('★ Ana (professional, female)', $voices['v-a']);
        $this->assertSame('Bruno (premade, male, american)', $voices['v-b']);
        $this->assertSame('Cvijeta (cloned)', $voices['v-c']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'next_page_token=page-2'));

        app(ElevenLabsClient::class)->voices();
        Http::assertSentCount(2);
    }

    public function test_the_subscription_reports_what_is_left(): void
    {
        Http::fake(['api.elevenlabs.io/v1/user/subscription' => Http::response([
            'character_count' => 12_500,
            'character_limit' => 100_000,
            'next_character_count_reset_unix' => 1_790_000_000,
            'tier' => 'creator',
            'status' => 'active',
        ])]);

        $subscription = app(ElevenLabsClient::class)->subscription();

        $this->assertSame(12_500, $subscription['character_count']);
        $this->assertSame(100_000, $subscription['character_limit']);
        $this->assertSame('creator', $subscription['tier']);
    }
}
