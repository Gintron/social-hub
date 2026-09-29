<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What `hub:doctor` says about OpenAI: whether there is a key and whether the model the hub will use is
 * there for it. It asks for the models, which is free, instead of finding out on the first caption.
 */
final class DoctorOpenAiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('hub.media_disk', 'public');
    }

    public function test_the_doctor_asks_openai_for_the_model_it_will_use(): void
    {
        config()->set('openai.api_key', 'test-key');
        config()->set('openai.model', 'gpt-6-sol');
        config()->set('openai.effort', 'medium');
        Http::fake([
            'api.openai.com/v1/models/gpt-6-sol' => Http::response(['id' => 'gpt-6-sol']),
            '*' => Http::response('', 200),
        ]);

        $this->artisan('hub:doctor', ['--skip-render' => true])->expectsOutputToContain('tekstovi: gpt-6-sol (medium)');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/models/gpt-6-sol');
    }

    public function test_the_doctor_says_when_openai_has_no_key_or_no_such_model(): void
    {
        config()->set('openai.api_key', null);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('hub:doctor', ['--skip-render' => true])->expectsOutputToContain('OPENAI_API_KEY not set');

        config()->set('openai.api_key', 'test-key');
        config()->set('openai.model', 'gpt-9');
        // A stub registered first wins, so the answers of the first half are cleared before the second is set.
        Http::swap(new Factory);
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'The model `gpt-9` does not exist', 'type' => 'invalid_request_error', 'code' => 'model_not_found']], 404),
        ]);

        $this->artisan('hub:doctor', ['--skip-render' => true])->expectsOutputToContain('model_not_found');
    }
}
