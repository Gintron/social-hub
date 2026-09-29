<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\CaptionResult;
use App\Ai\CaptionWriter;
use App\Ai\CaptionWriterException;
use App\Ai\OpenAiCaptionWriter;
use App\Ai\Schemas\CaptionSet;
use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeOpenAi;
use Tests\TestCase;

/**
 * The agent's writer: what it asks OpenAI, in what voice, and how a bad answer reaches the agent.
 */
final class OpenAiCaptionWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openai.api_key', 'test-key');
        config()->set('openai.model', 'gpt-6-sol');
        config()->set('openai.effort', 'medium');
        config()->set('openai.max_output_tokens', 16000);
        Sleep::fake();
    }

    public function test_it_writes_captions_from_the_items_data_in_the_brands_voice(): void
    {
        [$brand, $item] = $this->brandAndItem();
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer($this->captions()))]);

        $result = app(OpenAiCaptionWriter::class)->write($item, $brand);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $system = $data['input'][0]['content'];
            $user = $data['input'][1]['content'];
            $schema = $data['text']['format']['schema'];

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $data['model'] === 'gpt-6-sol'
                && $data['reasoning'] === ['effort' => 'medium']
                && $data['max_output_tokens'] === 16000
                && $data['text']['format']['strict'] === true
                && $schema['required'] === ['facebook', 'instagram', 'tiktok', 'hashtags', 'alt_text', 'note']
                && str_contains($system, 'Studentski-poslovi')
                && str_contains($system, 'https://studentski-poslovi.test')
                && str_contains($system, 'jasan i topao')
                && str_contains($system, 'Ne koristi uskličnike')
                && str_contains($system, 'Prijavi se na stranici')
                && str_contains($system, 'Posao za studente u jednom mjestu')
                && str_contains($system, '#posao #zagreb')
                && str_contains($system, 'TVRDA PRAVILA')
                && str_contains($user, '"title": "Konobar/ica"')
                && str_contains($user, '"kind": "job"')
                && str_contains($user, 'https://studentski-poslovi.test/posao/1')
                && ! str_contains($user, 'Prethodni pokušaj');
        });

        $this->assertInstanceOf(CaptionResult::class, $result);
        $this->assertSame('Konobar u Splitu, 7,00 €/h.', $result->captions->facebook);
        $this->assertSame('#posao #split', $result->captions->hashtags);
        $this->assertNull($result->captions->note);
        $this->assertSame('gpt-6-sol-2026-09-01', $result->model);
        $this->assertSame(120, $result->inputTokens);
        $this->assertSame(45, $result->outputTokens);
    }

    public function test_a_retry_carries_the_reason_the_last_attempt_was_thrown_away(): void
    {
        [$brand, $item] = $this->brandAndItem();
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer($this->captions()))]);

        app(OpenAiCaptionWriter::class)->write($item, $brand, ['Iznos 9,99 € ne postoji u podacima.']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->data()['input'][1]['content'], 'Prethodni pokušaj je odbačen')
            && str_contains($request->data()['input'][1]['content'], 'Iznos 9,99 € ne postoji u podacima.'));
    }

    public function test_an_answer_that_lacks_a_field_is_not_taken(): void
    {
        [$brand, $item] = $this->brandAndItem();
        $captions = $this->captions();
        unset($captions['tiktok']);
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer($captions))]);

        $this->expectException(CaptionWriterException::class);
        $this->expectExceptionMessage('tiktok');

        app(OpenAiCaptionWriter::class)->write($item, $brand);
    }

    public function test_the_model_declining_reaches_the_agent_as_a_caption_writer_exception(): void
    {
        [$brand, $item] = $this->brandAndItem();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Ne mogu.']]]],
        ])]);

        $this->expectException(CaptionWriterException::class);
        $this->expectExceptionMessage('odbio napisati objavu');

        app(OpenAiCaptionWriter::class)->write($item, $brand);
    }

    public function test_an_api_failure_reaches_the_agent_as_a_caption_writer_exception_with_the_reason(): void
    {
        [$brand, $item] = $this->brandAndItem();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 429)]);

        try {
            app(OpenAiCaptionWriter::class)->write($item, $brand);
            $this->fail('A failed request must reach the agent as a CaptionWriterException.');
        } catch (CaptionWriterException $e) {
            $this->assertStringContainsString('OpenAI 429', $e->getMessage());
            $this->assertStringContainsString('nema kredita', $e->getMessage());
        }
    }

    public function test_the_agent_gets_this_writer_unless_a_test_binds_another(): void
    {
        $this->assertInstanceOf(OpenAiCaptionWriter::class, app(CaptionWriter::class));
    }

    public function test_the_schema_is_one_openai_can_hold_a_model_to(): void
    {
        $schema = CaptionSet::schema();

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties'], 'strict mode allows no other fields');
        $this->assertSame(array_keys($schema['properties']), $schema['required'], 'strict mode wants every field required');
        $this->assertSame(['string', 'null'], $schema['properties']['note']['type'], 'the note is the one field that may be empty');

        foreach (['facebook', 'instagram', 'tiktok', 'hashtags', 'alt_text'] as $field) {
            $this->assertSame('string', $schema['properties'][$field]['type']);
            $this->assertNotSame('', $schema['properties'][$field]['description']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function captions(): array
    {
        return [
            'facebook' => 'Konobar u Splitu, 7,00 €/h.',
            'instagram' => 'Konobar u Splitu. Pošalji prijatelju kojem treba.',
            'tiktok' => "Konobar u Splitu – 7,00 €/h\nKratak opis.",
            'hashtags' => '#posao #split',
            'alt_text' => 'Slika oglasa.',
            'note' => null,
        ];
    }

    /**
     * @return array{0: Brand, 1: ContentItem}
     */
    private function brandAndItem(): array
    {
        $brand = Brand::factory()->create([
            'slug' => 'studentski-poslovi',
            'name' => 'Studentski-poslovi',
            'site_url' => 'https://studentski-poslovi.test',
            'voice' => [
                'tone' => 'jasan i topao',
                'rules' => 'Ne koristi uskličnike',
                'cta' => 'Prijavi se na stranici',
                'pitch' => 'Posao za studente u jednom mjestu.',
                'hashtags' => ['posao', 'zagreb'],
            ],
        ]);

        $item = ContentItem::factory()->for(Source::factory()->for($brand)->create())->for($brand)->create([
            'kind' => ContentKind::Job,
            'title' => 'Konobar/ica',
            'url' => 'https://studentski-poslovi.test/posao/1',
            'facts' => [['label' => 'LOKACIJA', 'value' => 'SPLIT'], ['label' => 'SATNICA', 'value' => '7,00 €/H']],
            'price' => ['current_cents' => 700, 'old_cents' => null, 'discount_pct' => null, 'currency' => 'EUR', 'unit_label' => '€/H'],
        ]);

        return [$brand, $item];
    }
}
