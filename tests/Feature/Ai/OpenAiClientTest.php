<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\OpenAiClient;
use App\Ai\OpenAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeOpenAi;
use Tests\TestCase;

/**
 * What the hub sends to OpenAI and how it reads the answers. The API is faked; the request is the
 * documented Responses API shape (structured outputs, strict).
 */
final class OpenAiClientTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['answer' => ['type' => 'string']],
        'required' => ['answer'],
        'additionalProperties' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openai.api_key', 'test-key');
        config()->set('openai.base_url', 'https://api.openai.com/v1');
        Sleep::fake();
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string, bool}>
     */
    public static function failures(): array
    {
        return [
            'a wrong key' => [401, ['error' => ['message' => 'Incorrect API key provided', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 'invalid_key', false],
            // A used-up balance is a 429 too — and waiting does not fill it.
            'a balance that is used up' => [429, ['error' => ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 'quota_exceeded', false],
            'a model that is not there' => [404, ['error' => ['message' => 'The model `gpt-9` does not exist', 'type' => 'invalid_request_error', 'code' => 'model_not_found']], 'model_not_found', false],
            'a project without access' => [403, ['error' => ['message' => 'Project does not have access to model', 'type' => 'invalid_request_error', 'code' => null]], 'forbidden', false],
            'a rejected request' => [400, ['error' => ['message' => "Unsupported value: 'reasoning.effort'", 'type' => 'invalid_request_error', 'code' => 'unsupported_value']], 'rejected', false],
        ];
    }

    public function test_it_asks_for_an_answer_that_fits_the_schema_with_the_key_in_a_header(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['answer' => 'Kruh je jeftin.']))]);

        $result = app(OpenAiClient::class)->structured('gpt-6-sol', 'Pišeš na hrvatskom.', 'Napiši rečenicu.', 'sentence', self::SCHEMA, effort: 'low', maxOutputTokens: 500);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->data() === [
                    'model' => 'gpt-6-sol',
                    'input' => [
                        ['role' => 'system', 'content' => 'Pišeš na hrvatskom.'],
                        ['role' => 'user', 'content' => 'Napiši rečenicu.'],
                    ],
                    'text' => ['format' => ['type' => 'json_schema', 'name' => 'sentence', 'strict' => true, 'schema' => self::SCHEMA]],
                    'store' => false,
                    'reasoning' => ['effort' => 'low'],
                    'max_output_tokens' => 500,
                ];
        });

        $this->assertSame(['answer' => 'Kruh je jeftin.'], $result->data);
        $this->assertSame('gpt-6-sol-2026-09-01', $result->model, 'the snapshot that answered, not the alias that was asked');
        $this->assertSame(120, $result->inputTokens);
        $this->assertSame(45, $result->outputTokens);
    }

    public function test_a_model_that_does_not_think_is_sent_no_effort(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['answer' => 'Da.']))]);

        app(OpenAiClient::class)->structured('gpt-4.1', 's', 'u', 'sentence', self::SCHEMA, effort: null);
        app(OpenAiClient::class)->structured('gpt-4.1', 's', 'u', 'sentence', self::SCHEMA, effort: '');

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('reasoning', $request->data()) && ! array_key_exists('max_output_tokens', $request->data()));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('failures')]
    public function test_a_refusal_says_why_and_is_not_retried(int $status, array $body, string $code, bool $retryable): void
    {
        Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

        try {
            app(OpenAiClient::class)->structured('gpt-6-sol', 's', 'u', 'sentence', self::SCHEMA);
            $this->fail('The refusal should have been raised.');
        } catch (OpenAiException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($retryable, $e->retryable);
            $this->assertStringContainsString((string) $status, $e->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_a_busy_api_is_waited_for_and_then_served(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Rate limit reached', 'type' => 'requests', 'code' => 'rate_limit_exceeded']], 429)
            ->push(FakeOpenAi::answer(['answer' => 'Sad ide.']))]);

        $result = app(OpenAiClient::class)->structured('gpt-6-sol', 's', 'u', 'sentence', self::SCHEMA);

        $this->assertSame('Sad ide.', $result->data['answer']);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_server_that_keeps_failing_is_given_up_on_after_four_tries(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('Bad gateway', 502)]);

        try {
            app(OpenAiClient::class)->structured('gpt-6-sol', 's', 'u', 'sentence', self::SCHEMA);
            $this->fail('An API that never recovers must surface.');
        } catch (OpenAiException $e) {
            $this->assertSame('unavailable', $e->errorCode);
            $this->assertTrue($e->retryable);
        }

        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
    }

    public function test_an_api_that_cannot_be_reached_is_unavailable_like_one_that_answers_with_a_5xx(): void
    {
        $attempts = 0;
        Http::fake(['api.openai.com/*' => function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        try {
            app(OpenAiClient::class)->structured('gpt-6-sol', 's', 'u', 'sentence', self::SCHEMA);
            $this->fail('A timeout must surface as an OpenAiException, never as a transport exception.');
        } catch (OpenAiException $e) {
            $this->assertSame('unavailable', $e->errorCode);
            $this->assertTrue($e->retryable);
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        $this->assertSame(4, $attempts, 'it waits and tries again before giving up');
    }

    public function test_the_model_declining_is_told_apart_from_an_error(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Ne mogu pomoći s tim.']]]],
        ])]);

        try {
            app(OpenAiClient::class)->structured('gpt-6-sol', 's', 'u', 'sentence', self::SCHEMA);
            $this->fail('A refusal is not an answer.');
        } catch (OpenAiException $e) {
            $this->assertTrue($e->refused());
            $this->assertStringContainsString('Ne mogu pomoći', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_an_answer_cut_short_is_incomplete_and_says_why(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [['type' => 'reasoning', 'summary' => []]],
        ])]);

        try {
            app(OpenAiClient::class)->structured('gpt-6-sol', 's', 'u', 'sentence', self::SCHEMA, maxOutputTokens: 50);
            $this->fail('Half an answer is not an answer.');
        } catch (OpenAiException $e) {
            $this->assertSame('incomplete', $e->errorCode);
            $this->assertStringContainsString('max_output_tokens', $e->getMessage());
        }
    }

    public function test_text_that_is_not_json_is_an_invalid_output(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Evo odgovora, ali nije JSON.']]]],
        ])]);

        $this->expectException(OpenAiException::class);
        $this->expectExceptionMessage('JSON');

        app(OpenAiClient::class)->structured('gpt-6-sol', 's', 'u', 'sentence', self::SCHEMA);
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config()->set('openai.api_key', null);
        Http::fake();

        $this->assertFalse(app(OpenAiClient::class)->configured());

        try {
            app(OpenAiClient::class)->structured('gpt-6-sol', 's', 'u', 'sentence', self::SCHEMA);
            $this->fail('A missing key must be raised before any request.');
        } catch (OpenAiException $e) {
            $this->assertSame('not_configured', $e->errorCode);
            $this->assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_asking_for_a_model_is_one_quick_request_that_is_not_retried(): void
    {
        Http::fake([
            'api.openai.com/v1/models/gpt-6-sol' => Http::response(['id' => 'gpt-6-sol', 'object' => 'model', 'owned_by' => 'system']),
            'api.openai.com/v1/models/gpt-9' => Http::response(['error' => ['message' => 'The model `gpt-9` does not exist', 'type' => 'invalid_request_error', 'code' => 'model_not_found']], 404),
        ]);

        $this->assertSame('gpt-6-sol', app(OpenAiClient::class)->model('gpt-6-sol')['id']);

        try {
            app(OpenAiClient::class)->model('gpt-9');
            $this->fail('A model that is not there must be raised.');
        } catch (OpenAiException $e) {
            $this->assertSame('model_not_found', $e->errorCode);
        }

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer test-key'));
    }
}
