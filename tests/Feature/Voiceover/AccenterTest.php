<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Models\VoiceoverAccent;
use App\Voiceover\Accenter;
use App\Voiceover\Stress;
use App\Voiceover\VoiceoverException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeOpenAi;
use Tests\TestCase;

/**
 * Where a model puts the stress, and how much of what it says is believed: it may add a mark to a word it
 * was shown, and nothing else. The model is faked; the request is the real one.
 */
final class AccenterTest extends TestCase
{
    use RefreshDatabase;

    private const KRUH = 'Kruh bijeli petsto grama u trgovini Konzum za jedan euro i četrdeset devet centi.';

    private const KRUH_ACCENTED = 'Kruh bijeli petsto gráma u trgovíni Kónzum za jedan euro i četrdeset devet centi.';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openai.api_key', 'test-key');
        config()->set('openai.model', 'gpt-6-sol');
        config()->set('openai.effort', 'medium');
        config()->set('openai.accents.model', null);
        config()->set('openai.accents.effort', null);
        Sleep::fake();
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string, bool}>
     */
    public static function failures(): array
    {
        return [
            'a wrong key' => [401, ['error' => ['message' => 'Incorrect API key', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 'accents_invalid_key', true],
            'a used-up balance' => [429, ['error' => ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 'accents_quota_exceeded', true],
            'a model that is not there' => [404, ['error' => ['message' => 'The model does not exist', 'type' => 'invalid_request_error', 'code' => 'model_not_found']], 'accents_model_not_found', true],
            'an API that is down' => [503, ['error' => ['message' => 'Overloaded', 'type' => 'server_error', 'code' => null]], 'accents_unavailable', false],
        ];
    }

    public function test_the_lines_of_a_video_are_asked_about_together_and_the_marks_come_back_in_place(): void
    {
        $this->answer([
            ['line' => 0, 'word' => 3, 'marked' => 'gráma'],
            ['line' => 0, 'word' => 5, 'marked' => 'trgovíni'],
            ['line' => 0, 'word' => 6, 'marked' => 'Kónzum'],
            ['line' => 1, 'word' => 0, 'marked' => 'Vrijédi'],
        ]);

        $lines = app(Accenter::class)->prepare([self::KRUH, 'Vrijedi do tridesetog rujna.', ''], Stress::ACUTE);

        $this->assertSame([self::KRUH_ACCENTED, 'Vrijédi do tridesetog rujna.', ''], $lines);

        // One request for the whole video, and the instructions and the words of every line are in it.
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $payload = json_decode($data['input'][1]['content'], true, flags: JSON_THROW_ON_ERROR);

            return $data['model'] === 'gpt-6-sol'
                && $data['reasoning'] === ['effort' => 'medium']
                && $data['text']['format']['name'] === 'stress_marks'
                && $data['text']['format']['strict'] === true
                && str_contains($data['input'][0]['content'], 'lektor')
                && array_column($payload['lines'], 'line') === [0, 1]
                && $payload['lines'][0]['text'] === self::KRUH
                // Only words with somewhere for the stress to fall, each with its number in the line.
                && array_column($payload['lines'][0]['words'], 'word') === [1, 2, 3, 5, 6, 8, 9, 11, 12, 13]
                && $payload['lines'][0]['words'][3] === ['word' => 5, 'text' => 'trgovini'];
        });
    }

    public function test_a_line_that_has_been_marked_is_never_asked_about_again(): void
    {
        $this->answer([['line' => 0, 'word' => 6, 'marked' => 'Kónzum']]);
        $first = app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE);

        $second = app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE);

        $this->assertSame($first, $second, 'the same text goes to the voice every time, so a clip that was paid for is found again');
        $this->assertSame(1, VoiceoverAccent::query()->count());
        Http::assertSentCount(1);
    }

    public function test_only_the_lines_nobody_has_marked_are_asked_for(): void
    {
        $this->answers(
            [['line' => 0, 'word' => 0, 'marked' => 'Kónzum']],
            [['line' => 0, 'word' => 0, 'marked' => 'Plódine']],
        );

        app(Accenter::class)->prepare(['Konzum bijeli.'], Stress::ACUTE);
        $lines = app(Accenter::class)->prepare(['Konzum bijeli.', 'Plodine bijeli.'], Stress::ACUTE);

        $this->assertSame(['Kónzum bijeli.', 'Plódine bijeli.'], $lines);
        $this->assertSame(2, VoiceoverAccent::query()->count());

        // The second request carried the line that was new, numbered from 0, and not the one already known.
        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->data()['input'][1]['content'], true, flags: JSON_THROW_ON_ERROR);

            return $payload['lines'] === [['line' => 0, 'text' => 'Plodine bijeli.', 'words' => [['word' => 0, 'text' => 'Plodine'], ['word' => 1, 'text' => 'bijeli']]]];
        });
    }

    public function test_a_mark_that_is_not_what_it_claims_to_be_is_dropped_and_the_rest_is_kept(): void
    {
        $this->answer([
            ['line' => 0, 'word' => 6, 'marked' => 'Kónzum'],
            // A letter changed: not the same word.
            ['line' => 0, 'word' => 3, 'marked' => 'gráme'],
            // A word that was not offered (one vowel).
            ['line' => 0, 'word' => 0, 'marked' => 'Krúh'],
            // A line that was not sent.
            ['line' => 5, 'word' => 1, 'marked' => 'bíjeli'],
            // The same word twice.
            ['line' => 0, 'word' => 6, 'marked' => 'Konzúm'],
            // Not an entry at all.
            ['line' => 0],
        ]);

        $lines = app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE);

        $this->assertSame(['Kruh bijeli petsto grama u trgovini Kónzum za jedan euro i četrdeset devet centi.'], $lines);
        // A JSON column keeps its keys in its own order; what matters is which word and which vowel.
        $this->assertEquals([['word' => 6, 'at' => 1]], VoiceoverAccent::query()->firstOrFail()->marks);
    }

    public function test_a_line_the_model_had_nothing_to_say_about_is_settled_too(): void
    {
        $this->answer([]);

        $this->assertSame([self::KRUH], app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE));
        $this->assertSame([self::KRUH], app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE));

        $this->assertSame([], VoiceoverAccent::query()->firstOrFail()->marks);
        Http::assertSentCount(1);
    }

    public function test_lines_with_no_word_worth_asking_about_ask_nothing(): void
    {
        Http::fake();

        $lines = app(Accenter::class)->prepare(['Da, to je to.', '', '  '], Stress::ACUTE);

        $this->assertSame(['Da, to je to.', '', '  '], $lines);
        Http::assertNothingSent();
    }

    public function test_the_style_is_a_matter_of_telling_not_of_asking(): void
    {
        $this->answer([['line' => 0, 'word' => 6, 'marked' => 'Kónzum']]);

        $acute = app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE);
        $caps = app(Accenter::class)->prepare([self::KRUH], Stress::CAPS);
        $off = app(Accenter::class)->prepare([self::KRUH], Stress::OFF);

        $this->assertStringContainsString('Kónzum', $acute[0]);
        $this->assertStringContainsString('KOnzum', $caps[0]);
        $this->assertSame([self::KRUH], $off);
        Http::assertSentCount(1);
    }

    public function test_with_the_stress_off_nothing_is_asked_and_no_key_is_needed(): void
    {
        config()->set('openai.api_key', null);
        Http::fake();

        $this->assertSame([self::KRUH], app(Accenter::class)->prepare([self::KRUH], Stress::OFF));
        Http::assertNothingSent();
    }

    public function test_the_accent_model_and_how_hard_it_thinks_can_differ_from_the_caption_writers(): void
    {
        config()->set('openai.accents.model', 'gpt-6-astra');
        config()->set('openai.accents.effort', 'low');
        $this->answers([], []);

        app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE);

        Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'gpt-6-astra' && $request->data()['reasoning'] === ['effort' => 'low']);

        // What one model marked is not what another would have: a new model is asked again.
        config()->set('openai.accents.model', 'gpt-6-luna');
        app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE);

        Http::assertSentCount(2);
        $this->assertSame(2, VoiceoverAccent::query()->count());
    }

    public function test_when_two_renders_mark_the_same_line_at_once_the_first_answer_is_the_one_kept(): void
    {
        $line = 'Konzum bijeli.';

        Http::fake(['api.openai.com/*' => function () use ($line) {
            // The other render finishes while this one waits for OpenAI.
            VoiceoverAccent::query()->create([
                'hash' => hash('sha256', json_encode([1, 'gpt-6-sol', $line], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'text' => $line,
                'marks' => [['word' => 1, 'at' => 1]],
                'model' => 'gpt-6-sol',
            ]);

            return Http::response(FakeOpenAi::answer(['marks' => [['line' => 0, 'word' => 0, 'marked' => 'Kónzum']]]));
        }]);

        $this->assertSame(['Konzum bíjeli.'], app(Accenter::class)->prepare([$line], Stress::ACUTE));
        $this->assertSame(1, VoiceoverAccent::query()->count());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('failures')]
    public function test_a_failure_is_named_after_its_cause_and_nothing_is_kept(int $status, array $body, string $code, bool $needsAttention): void
    {
        Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

        try {
            app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE);
            $this->fail('Without the marks there is no voice: the failure must reach the caller.');
        } catch (VoiceoverException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($needsAttention, $e->needsAttention());
            $this->assertStringContainsString('Naglasci', $e->getMessage());
        }

        $this->assertSame(0, VoiceoverAccent::query()->count(), 'a line that could not be marked is asked about again next time');
    }

    public function test_the_model_declining_and_a_missing_key_are_failures_too(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Ne mogu.']]]],
        ])]);

        try {
            app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE);
            $this->fail('A refusal is not a list of marks.');
        } catch (VoiceoverException $e) {
            $this->assertSame('accents_refused', $e->errorCode);
        }

        config()->set('openai.api_key', null);

        try {
            app(Accenter::class)->prepare([self::KRUH], Stress::ACUTE);
            $this->fail('Without a key there is nothing to ask.');
        } catch (VoiceoverException $e) {
            $this->assertSame('accents_not_configured', $e->errorCode);
            $this->assertTrue($e->needsAttention());
            $this->assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     */
    private function answer(array $marks): void
    {
        $this->answers($marks);
    }

    /**
     * One answer for each request the test expects; a request beyond them fails the test.
     *
     * @param  list<array<string, mixed>>  ...$answers
     */
    private function answers(array ...$answers): void
    {
        $sequence = Http::sequence();

        foreach ($answers as $marks) {
            $sequence->push(FakeOpenAi::answer(['marks' => $marks]));
        }

        Http::fake(['api.openai.com/*' => $sequence]);
    }
}
