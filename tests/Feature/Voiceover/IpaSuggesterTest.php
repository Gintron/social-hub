<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Voiceover\IpaSuggester;
use App\Voiceover\VoiceoverException;
use App\Voiceover\VoiceoverSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeOpenAi;
use Tests\TestCase;

/**
 * A model's guess at the IPA of one word: asked for with the sentence the word is in, and held to what a
 * transcription of that word is. The model is faked; the request is the real one.
 */
final class IpaSuggesterTest extends TestCase
{
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

    public function test_the_word_and_its_sentence_are_asked_about_and_the_ipa_comes_back_clean(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['ipa' => "/'lɛtka/"]))]);

        $ipa = app(IpaSuggester::class)->suggest('letka', 'S letka ravno na listu.');

        $this->assertSame('ˈlɛtka', $ipa);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $payload = json_decode($data['input'][1]['content'], true, flags: JSON_THROW_ON_ERROR);

            return $data['model'] === 'gpt-6-sol'
                && $data['text']['format']['name'] === 'ipa_suggestion'
                && str_contains($data['input'][0]['content'], 'fonetičar')
                && str_contains($data['input'][0]['content'], 'letka → ˈlɛtka')
                && $payload === ['word' => 'letka', 'sentence' => 'S letka ravno na listu.'];
        });
    }

    public function test_an_answer_that_is_not_a_transcription_of_the_word_is_no_suggestion(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(FakeOpenAi::answer(['ipa' => 'ˈmatʃku']))
            ->push(FakeOpenAi::answer(['ipa' => 'ˈlɛtka ˈlɛtka']))
            ->push(FakeOpenAi::answer(['ipa' => 'letka']))]);

        $this->assertNull(app(IpaSuggester::class)->suggest('letka'));
        $this->assertNull(app(IpaSuggester::class)->suggest('letka'));
        $this->assertNull(app(IpaSuggester::class)->suggest('letka'));
    }

    public function test_something_that_is_not_one_word_is_not_asked_about(): void
    {
        Http::fake();

        $this->assertNull(app(IpaSuggester::class)->suggest('dvije riječi'));
        $this->assertNull(app(IpaSuggester::class)->suggest('letka2'));
        $this->assertNull(app(IpaSuggester::class)->suggest(''));
        Http::assertNothingSent();
    }

    public function test_a_model_that_cannot_be_asked_is_named_after_its_cause(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 401)]);

        try {
            app(IpaSuggester::class)->suggest('letka');
            $this->fail('A refused request must reach the caller.');
        } catch (VoiceoverException $e) {
            $this->assertSame('ipa_invalid_key', $e->errorCode);
        }
    }

    public function test_the_words_of_a_brand_are_read_as_one_word_each_with_a_sendable_ipa(): void
    {
        $settings = VoiceoverSettings::fromArray(['words' => [
            ['find' => ' letka ', 'ipa' => "'lɛtka"],
            ['find' => 'Letak', 'ipa' => '[ˈlɛːtak]'],
            ['find' => 'dvije riječi', 'ipa' => 'ˈlɛtka'],
            ['find' => 'listu', 'ipa' => 'ˈlɛt"ka'],
            ['find' => '', 'ipa' => 'ˈlɛtka'],
            ['find' => 'prazno', 'ipa' => ''],
            'not a row',
        ]]);

        $this->assertSame(['letka' => 'ˈlɛtka', 'Letak' => 'ˈlɛːtak'], $settings->words);
    }

    public function test_the_ipa_is_a_way_of_writing_not_a_switch_and_a_model_is_only_needed_when_asked_for(): void
    {
        // The panel offers this model too, and it is not one that reads IPA: only v4 is in elevenlabs.ipa_models.
        config()->set('elevenlabs.models', ['eleven_v4' => 'v4', 'eleven_multilingual_v2' => 'Multilingual v2 (ne čita IPA)']);
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('openai.api_key', null);
        config()->set('elevenlabs.ipa', 'tag');
        config()->set('elevenlabs.ipa_auto', false);

        $plain = VoiceoverSettings::fromArray(['voice_id' => 'v']);
        $withWords = VoiceoverSettings::fromArray(['voice_id' => 'v', 'words' => [['find' => 'letka', 'ipa' => 'ˈlɛtka']]]);
        $auto = VoiceoverSettings::fromArray(['voice_id' => 'v', 'ipa_auto' => true]);
        $autoOnOldModel = VoiceoverSettings::fromArray(['voice_id' => 'v', 'ipa_auto' => true, 'model' => 'eleven_multilingual_v2']);

        $this->assertNull($plain->whyNot());
        $this->assertNull($withWords->whyNot(), 'chosen words need no key but ElevenLabs\'');
        $this->assertStringContainsString('OPENAI_API_KEY', (string) $auto->whyNot());
        $this->assertNull($autoOnOldModel->whyNot(), 'a model that does not read IPA is not asked to write it');
        $this->assertTrue($autoOnOldModel->ipaIgnored());
        $this->assertFalse($plain->ipaIgnored());
        $this->assertSame('tag', $plain->ipaStyle());
        $this->assertSame('off', $autoOnOldModel->ipaStyle());
    }
}
