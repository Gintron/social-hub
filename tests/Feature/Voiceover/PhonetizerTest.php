<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Models\VoiceoverTranscription;
use App\Voiceover\Ipa;
use App\Voiceover\Phonetizer;
use App\Voiceover\VoiceoverException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeOpenAi;
use Tests\TestCase;

/**
 * How a model says the words, and how much of what it says is believed: it may give the IPA of a word it was
 * shown, and nothing else. The model is faked; the request is the real one.
 */
final class PhonetizerTest extends TestCase
{
    use RefreshDatabase;

    private const KRUH = 'Kruh bijeli petsto grama u trgovini Konzum za jedan euro i četrdeset devet centi.';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openai.api_key', 'test-key');
        config()->set('openai.model', 'gpt-6-sol');
        config()->set('openai.effort', 'medium');
        config()->set('openai.ipa.model', null);
        config()->set('openai.ipa.effort', null);
        Sleep::fake();
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string, bool}>
     */
    public static function failures(): array
    {
        return [
            'a wrong key' => [401, ['error' => ['message' => 'Incorrect API key', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 'ipa_invalid_key', true],
            'a used-up balance' => [429, ['error' => ['message' => 'You exceeded your current quota', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']], 'ipa_quota_exceeded', true],
            'a model that is not there' => [404, ['error' => ['message' => 'The model does not exist', 'type' => 'invalid_request_error', 'code' => 'model_not_found']], 'ipa_model_not_found', true],
            'an API that is down' => [503, ['error' => ['message' => 'Overloaded', 'type' => 'server_error', 'code' => null]], 'ipa_unavailable', false],
        ];
    }

    public function test_the_lines_of_a_video_are_asked_about_together_and_the_ipa_comes_back_in_place(): void
    {
        $this->answer([
            ['line' => 0, 'word' => 3, 'ipa' => 'ˈɡrama'],
            ['line' => 0, 'word' => 5, 'ipa' => 'trˈɡɔʋini'],
            ['line' => 0, 'word' => 6, 'ipa' => 'ˈkɔnzum'],
            ['line' => 1, 'word' => 0, 'ipa' => "vri'jɛdi"],
        ]);

        $lines = app(Phonetizer::class)->prepare([self::KRUH, 'Vrijedi do tridesetog rujna.', ''], Ipa::SLASH, auto: true);

        $this->assertSame([
            'Kruh bijeli petsto /ˈɡrama/ u /trˈɡɔʋini/ /ˈkɔnzum/ za jedan euro i četrdeset devet centi.',
            '/vriˈjɛdi/ do tridesetog rujna.',
            '',
        ], $lines);

        // One request for the whole video, and the instructions and the words of every line are in it.
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $payload = json_decode($data['input'][1]['content'], true, flags: JSON_THROW_ON_ERROR);

            return $data['model'] === 'gpt-6-sol'
                && $data['reasoning'] === ['effort' => 'medium']
                && $data['text']['format']['name'] === 'ipa_transcriptions'
                && $data['text']['format']['strict'] === true
                && str_contains($data['input'][0]['content'], 'fonetičar')
                && array_column($payload['lines'], 'line') === [0, 1]
                && $payload['lines'][0]['text'] === self::KRUH
                // Only words that are more than a preposition, each with its number in the line.
                && array_column($payload['lines'][0]['words'], 'word') === [0, 1, 2, 3, 5, 6, 8, 9, 11, 12, 13]
                && $payload['lines'][0]['words'][4] === ['word' => 5, 'text' => 'trgovini'];
        });
    }

    public function test_the_ipa_is_told_in_the_style_chosen_and_asked_for_once(): void
    {
        $this->answer([['line' => 0, 'word' => 6, 'ipa' => 'ˈkɔnzum']]);

        $tag = app(Phonetizer::class)->prepare([self::KRUH], Ipa::TAG, auto: true);
        $slash = app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true);
        $bare = app(Phonetizer::class)->prepare([self::KRUH], Ipa::BARE, auto: true);
        $off = app(Phonetizer::class)->prepare([self::KRUH], Ipa::OFF);

        $this->assertStringContainsString('<phoneme alphabet="ipa" ph="ˈkɔnzum">Konzum</phoneme>', $tag[0]);
        $this->assertStringContainsString('trgovini /ˈkɔnzum/ za', $slash[0]);
        $this->assertStringContainsString('trgovini ˈkɔnzum za', $bare[0]);
        $this->assertSame([self::KRUH], $off);
        Http::assertSentCount(1);
    }

    public function test_a_line_that_has_been_transcribed_is_never_asked_about_again(): void
    {
        $this->answer([['line' => 0, 'word' => 6, 'ipa' => 'ˈkɔnzum']]);
        $first = app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true);

        $second = app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true);

        $this->assertSame($first, $second, 'the same text goes to the voice every time, so a clip that was paid for is found again');
        $this->assertSame(1, VoiceoverTranscription::query()->count());
        Http::assertSentCount(1);
    }

    public function test_only_the_lines_nobody_has_transcribed_are_asked_for(): void
    {
        $this->answers(
            [['line' => 0, 'word' => 0, 'ipa' => 'ˈkɔnzum']],
            [['line' => 0, 'word' => 0, 'ipa' => 'ˈplɔdine']],
        );

        app(Phonetizer::class)->prepare(['Konzum bijeli.'], Ipa::SLASH, auto: true);
        $lines = app(Phonetizer::class)->prepare(['Konzum bijeli.', 'Plodine bijeli.'], Ipa::SLASH, auto: true);

        $this->assertSame(['/ˈkɔnzum/ bijeli.', '/ˈplɔdine/ bijeli.'], $lines);
        $this->assertSame(2, VoiceoverTranscription::query()->count());

        // The second request carried the line that was new, numbered from 0, and not the one already known.
        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->data()['input'][1]['content'], true, flags: JSON_THROW_ON_ERROR);

            return $payload['lines'] === [['line' => 0, 'text' => 'Plodine bijeli.', 'words' => [['word' => 0, 'text' => 'Plodine'], ['word' => 1, 'text' => 'bijeli']]]];
        });
    }

    public function test_an_answer_that_is_not_what_it_claims_to_be_is_dropped_and_the_rest_is_kept(): void
    {
        $this->answer([
            ['line' => 0, 'word' => 6, 'ipa' => 'ˈkɔnzum'],
            // The IPA of another word.
            ['line' => 0, 'word' => 3, 'ipa' => 'ˈmatʃku'],
            // A word that was not offered (two letters).
            ['line' => 0, 'word' => 7, 'ipa' => 'ˈza'],
            // A line that was not sent.
            ['line' => 5, 'word' => 1, 'ipa' => 'ˈbijɛli'],
            // The same word twice.
            ['line' => 0, 'word' => 6, 'ipa' => 'kɔnˈzum'],
            // Something that would end the tag.
            ['line' => 0, 'word' => 8, 'ipa' => 'ˈjɛdan"></phoneme>'],
            // Not an entry at all.
            ['line' => 0],
        ]);

        $lines = app(Phonetizer::class)->prepare([self::KRUH], Ipa::TAG, auto: true);

        $this->assertSame(['Kruh bijeli petsto grama u trgovini <phoneme alphabet="ipa" ph="ˈkɔnzum">Konzum</phoneme> za jedan euro i četrdeset devet centi.'], $lines);
        $this->assertEquals([['word' => 6, 'ipa' => 'ˈkɔnzum']], VoiceoverTranscription::query()->firstOrFail()->marks);
    }

    public function test_a_line_the_model_had_nothing_to_say_about_is_settled_too(): void
    {
        $this->answer([]);

        $this->assertSame([self::KRUH], app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true));
        $this->assertSame([self::KRUH], app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true));

        $this->assertSame([], VoiceoverTranscription::query()->firstOrFail()->marks);
        Http::assertSentCount(1);
    }

    public function test_lines_with_no_word_worth_asking_about_ask_nothing(): void
    {
        Http::fake();

        $lines = app(Phonetizer::class)->prepare(['Da, to je to.', 'Na 1 i 2.', '', '  '], Ipa::SLASH, auto: true);

        $this->assertSame(['Da, to je to.', 'Na 1 i 2.', '', '  '], $lines);
        Http::assertNothingSent();
    }

    public function test_the_words_a_person_chose_are_put_right_without_asking_anyone(): void
    {
        config()->set('openai.api_key', null);
        Http::fake();
        $words = ['letka' => 'ˈlɛtka', 'Konzum' => 'ˈkɔnzum'];

        $lines = app(Phonetizer::class)->prepare(['S letka u Konzumu i Letka u konzum.', 'Bez ičega.'], Ipa::TAG, $words);

        $this->assertSame([
            'S <phoneme alphabet="ipa" ph="ˈlɛtka">letka</phoneme> u Konzumu i <phoneme alphabet="ipa" ph="ˈlɛtka">Letka</phoneme> u <phoneme alphabet="ipa" ph="ˈkɔnzum">konzum</phoneme>.',
            'Bez ičega.',
        ], $lines, 'a word in any case, whole: "Konzumu" is another form and has no rule');
        Http::assertNothingSent();
    }

    public function test_a_persons_word_wins_over_the_models_and_the_model_still_covers_the_rest(): void
    {
        $this->answer([
            ['line' => 0, 'word' => 3, 'ipa' => 'ˈɡrɑːma'],
            ['line' => 0, 'word' => 6, 'ipa' => 'ˈkɔnzum'],
        ]);

        $lines = app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, ['grama' => 'ˈɡrama'], auto: true);

        $this->assertStringContainsString('petsto /ˈɡrama/ u', $lines[0], 'what the person chose is what is said');
        $this->assertStringContainsString('trgovini /ˈkɔnzum/ za', $lines[0]);
    }

    public function test_the_model_is_not_asked_when_the_style_is_off_or_nothing_is_asked_of_it(): void
    {
        Http::fake();

        $this->assertSame([self::KRUH], app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, [], auto: false));
        $this->assertSame([self::KRUH], app(Phonetizer::class)->prepare([self::KRUH], Ipa::OFF, ['grama' => 'ˈɡrama'], auto: true));
        Http::assertNothingSent();
    }

    public function test_with_the_ipa_off_nothing_is_asked_and_no_key_is_needed(): void
    {
        config()->set('openai.api_key', null);
        Http::fake();

        $this->assertSame([self::KRUH], app(Phonetizer::class)->prepare([self::KRUH], Ipa::OFF));
        Http::assertNothingSent();
    }

    public function test_the_model_and_how_hard_it_thinks_can_differ_from_the_caption_writers(): void
    {
        config()->set('openai.ipa.model', 'gpt-6-astra');
        config()->set('openai.ipa.effort', 'low');
        $this->answers([], []);

        app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true);

        Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'gpt-6-astra' && $request->data()['reasoning'] === ['effort' => 'low']);

        // What one model wrote is not what another would have: a new model is asked again.
        config()->set('openai.ipa.model', 'gpt-6-luna');
        app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true);

        Http::assertSentCount(2);
        $this->assertSame(2, VoiceoverTranscription::query()->count());
    }

    public function test_when_two_renders_transcribe_the_same_line_at_once_the_first_answer_is_the_one_kept(): void
    {
        $line = 'Konzum bijeli.';

        Http::fake(['api.openai.com/*' => function () use ($line) {
            // The other render finishes while this one waits for OpenAI.
            VoiceoverTranscription::query()->create([
                'hash' => hash('sha256', json_encode([1, 'gpt-6-sol', $line], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                'text' => $line,
                'marks' => [['word' => 1, 'ipa' => 'ˈbijɛli']],
                'model' => 'gpt-6-sol',
            ]);

            return Http::response(FakeOpenAi::answer(['marks' => [['line' => 0, 'word' => 0, 'ipa' => 'ˈkɔnzum']]]));
        }]);

        $this->assertSame(['Konzum /ˈbijɛli/.'], app(Phonetizer::class)->prepare([$line], Ipa::SLASH, auto: true));
        $this->assertSame(1, VoiceoverTranscription::query()->count());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('failures')]
    public function test_a_failure_is_named_after_its_cause_and_nothing_is_kept(int $status, array $body, string $code, bool $needsAttention): void
    {
        Http::fake(['api.openai.com/*' => Http::response($body, $status)]);

        try {
            app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true);
            $this->fail('Without the IPA there is no voice: the failure must reach the caller.');
        } catch (VoiceoverException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($needsAttention, $e->needsAttention());
            $this->assertStringContainsString('Izgovor', $e->getMessage());
        }

        $this->assertSame(0, VoiceoverTranscription::query()->count(), 'a line that could not be transcribed is asked about again next time');
    }

    public function test_the_model_declining_and_a_missing_key_are_failures_too(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Ne mogu.']]]],
        ])]);

        try {
            app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true);
            $this->fail('A refusal is not a list of transcriptions.');
        } catch (VoiceoverException $e) {
            $this->assertSame('ipa_refused', $e->errorCode);
        }

        config()->set('openai.api_key', null);

        try {
            app(Phonetizer::class)->prepare([self::KRUH], Ipa::SLASH, auto: true);
            $this->fail('Without a key there is nothing to ask.');
        } catch (VoiceoverException $e) {
            $this->assertSame('ipa_not_configured', $e->errorCode);
            $this->assertTrue($e->needsAttention());
            $this->assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        }
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     */
    public function test_a_word_the_pronunciation_list_says_gets_no_ipa_by_hand_or_from_a_model(): void
    {
        // "letka" is what the pronunciation list says, so it is heard as the list has it; the model's mark on it is dropped too,
        // and the other words keep their IPA.
        $this->answer([['line' => 0, 'word' => 4, 'ipa' => 'ˈlɛtka'], ['line' => 0, 'word' => 6, 'ipa' => 'ˈkɔnzum']]);

        $lines = app(Phonetizer::class)->prepare(
            ['Dodaj prvi proizvod s letka i Konzum.'],
            Ipa::SLASH,
            ['letka' => 'ˈlɛtka', 'Konzum' => 'ˈkɔnzum'],
            auto: true,
            pronounced: ['letka'],
        );

        $this->assertSame(['Dodaj prvi proizvod s letka i /ˈkɔnzum/.'], $lines);
    }

    public function test_a_word_the_pronunciation_list_says_is_not_wrapped_when_it_is_the_brands_word_too(): void
    {
        $lines = app(Phonetizer::class)->prepare(['Preuzmi letka.'], Ipa::TAG, ['letka' => 'ˈlɛtka'], pronounced: ['letka']);

        $this->assertSame(['Preuzmi letka.'], $lines, 'the pronunciation is the text the voice has, with no phoneme tag around it');
    }

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
