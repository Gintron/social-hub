<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\Source;
use App\Models\Voiceover;
use App\Rendering\ScenePlanner;
use App\Voiceover\Narrator;
use App\Voiceover\VoiceoverException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\FakeOpenAi;
use Tests\Support\FakeSpeech;
use Tests\TestCase;

/**
 * From a draft to spoken clips: what is written, what is checked, what is sent to the voice and what is
 * never paid for twice.
 */
final class NarratorTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('elevenlabs.disk', 'local');
        FakeSpeech::fake($this->requests);
    }

    public function test_every_slide_is_spoken_and_what_the_voice_receives_has_no_digits_in_it(): void
    {
        $draft = $this->draft();

        $narration = app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertCount(3, $narration->clips);
        $this->assertSame([
            'Kruh bijeli petsto grama u trgovini Konzum za jedan euro i četrdeset devet centi.',
            'Popust dvadeset pet posto. Vrijedi do tridesetog rujna.',
            'Preuzmi Listo. Dodaj prvi proizvod s letka.',
        ], array_column($this->requests, 'text'));
        $this->assertSame('voice-1', $narration->voiceId);
        $this->assertSame('eleven_v4', $narration->model);
        $this->assertSame(-14.0, $narration->musicGainDb);
        $this->assertSame(array_sum(array_map('mb_strlen', array_column($this->requests, 'text'))), $narration->characters());

        foreach ($narration->clips as $clip) {
            $this->assertFileExists($clip->path);
            $this->assertGreaterThan(0.5, $clip->seconds);
        }

        $description = $narration->describe();
        $this->assertSame('ok', $description['status']);
        $this->assertSame('Kruh bijeli 500 g u trgovini Konzum za 1,49 €.', $description['script'][0]['text'], 'the script is kept as written, for a person to read');
    }

    public function test_the_second_video_with_the_same_words_costs_nothing(): void
    {
        $draft = $this->draft();
        $scenes = app(ScenePlanner::class)->forDraft($draft);

        app(Narrator::class)->narrate($draft, $scenes);
        app(Narrator::class)->narrate($draft, $scenes);

        $this->assertCount(3, $this->requests);
        $this->assertSame(3, Voiceover::query()->count());
    }

    public function test_the_brand_closing_line_is_spoken_once_for_every_video_that_follows(): void
    {
        $first = $this->draft('Kruh bijeli 500 g');
        $second = $this->draft('Mlijeko 1 l', $first->brand);

        app(Narrator::class)->narrate($first, app(ScenePlanner::class)->forDraft($first));
        app(Narrator::class)->narrate($second, app(ScenePlanner::class)->forDraft($second));

        // Two hooks; the card (same discount, same date) and the closing line are said once between them.
        $this->assertCount(4, $this->requests);
        $this->assertCount(1, array_filter(array_column($this->requests, 'text'), fn (string $text): bool => str_starts_with($text, 'Preuzmi Listo')));
        $this->assertCount(1, array_filter(array_column($this->requests, 'text'), fn (string $text): bool => str_starts_with($text, 'Popust')));
    }

    public function test_the_brands_pronunciations_are_applied_before_the_voice_hears_a_word(): void
    {
        $draft = $this->draft(voiceover: ['pronunciations' => [['find' => 'Listo', 'say' => 'Lisdo']]]);

        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertSame('Preuzmi Lisdo. Dodaj prvi proizvod s letka.', $this->requests[2]['text']);
    }

    public function test_the_brands_voice_settings_reach_the_request(): void
    {
        $draft = $this->draft(voiceover: ['speed' => 1.1, 'stability' => 0.7, 'model' => 'eleven_v4']);

        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertSame('eleven_v4', $this->requests[0]['model_id']);
        $this->assertSame(1.1, $this->requests[0]['voice_settings']['speed']);
        $this->assertSame(0.7, $this->requests[0]['voice_settings']['stability']);
    }

    public function test_a_line_that_says_a_price_the_offer_does_not_carry_is_refused_before_anything_is_paid_for(): void
    {
        $draft = $this->draft();

        try {
            app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft), ['Kruh bijeli samo za 0,99 €.', '', '']);
            $this->fail('An invented price must not be spoken.');
        } catch (VoiceoverException $e) {
            $this->assertSame('script_rejected', $e->errorCode);
            $this->assertStringContainsString('0,99 €', $e->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    public function test_a_script_that_says_a_price_the_offer_does_carry_is_spoken(): void
    {
        $draft = $this->draft();

        $narration = app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft), ['Kruh bijeli za 1,49 € umjesto 1,99 €.', '', 'Preuzmi Listo.']);

        $this->assertCount(3, $narration->clips);
        $this->assertNull($narration->clips[1], 'an empty line is a slide left to the music');
        $this->assertSame('Kruh bijeli za jedan euro i četrdeset devet centi umjesto jedan euro i devedeset devet centi.', $this->requests[0]['text']);
    }

    public function test_the_voice_never_reads_out_a_web_address(): void
    {
        $draft = $this->draft();

        $this->expectException(VoiceoverException::class);
        $this->expectExceptionMessage('poveznicu');

        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft), ['Više na https://uselisto.com', '', '']);
    }

    public function test_a_script_with_nothing_to_say_says_nothing(): void
    {
        $draft = $this->draft();

        $this->assertNull(app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft), ['', '  ', '🛒']));
        $this->assertSame([], $this->requests);
    }

    public function test_a_script_longer_than_the_budget_is_refused(): void
    {
        config()->set('elevenlabs.max_characters_per_video', 40);
        $draft = $this->draft();

        try {
            app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));
            $this->fail('The character budget is a fence.');
        } catch (VoiceoverException $e) {
            $this->assertSame('script_rejected', $e->errorCode);
            $this->assertStringContainsString('znakova', $e->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    public function test_a_roundups_headline_is_the_brands_own_text_and_is_not_held_to_the_offers_figures(): void
    {
        $draft = $this->roundup('Akcije do 50 % popusta');
        $scenes = app(ScenePlanner::class)->forDraft($draft);

        $narration = app(Narrator::class)->narrate($draft, $scenes);

        // The cover says the headline as the brand wrote it, then the deepest cut the items themselves carry.
        $this->assertNotNull($narration);
        $this->assertSame('Akcije do pedeset posto popusta. Popusti do dvadeset pet posto.', $this->requests[0]['text']);

        // The exception covers the headline and nothing else on the line: a figure a person adds is still checked.
        try {
            app(Narrator::class)->narrate($draft, $scenes, ['Akcije do 50 % popusta za samo 0,01 €.', '', '', '']);
            $this->fail('A price the offers do not carry must stop the render even next to the headline.');
        } catch (VoiceoverException $e) {
            $this->assertSame('script_rejected', $e->errorCode);
            $this->assertStringContainsString('0,01 €', $e->getMessage());
            $this->assertStringNotContainsString('50 %', $e->getMessage());
        }
    }

    public function test_without_a_key_or_a_voice_it_does_not_try(): void
    {
        $withoutVoice = $this->draft(voiceover: ['voice_id' => null]);

        try {
            app(Narrator::class)->narrate($withoutVoice, app(ScenePlanner::class)->forDraft($withoutVoice));
            $this->fail('A brand with no voice cannot speak.');
        } catch (VoiceoverException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }

        config()->set('elevenlabs.api_key', null);
        $draft = $this->draft();

        $this->expectException(VoiceoverException::class);
        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));
    }

    public function test_an_api_failure_surfaces_with_its_reason(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'quota_exceeded', 'message' => 'Out of credits']], 401)]);
        $draft = $this->draft();

        try {
            app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));
            $this->fail('The quota error must reach the caller.');
        } catch (VoiceoverException $e) {
            $this->assertSame('quota_exceeded', $e->errorCode);
            $this->assertTrue($e->needsAttention());
        }
    }

    public function test_the_ipa_of_the_words_is_written_before_anything_is_spoken(): void
    {
        config()->set('openai.api_key', 'test-key');
        config()->set('openai.model', 'gpt-6-sol');
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['marks' => [
            ['line' => 0, 'word' => 3, 'ipa' => 'ˈɡrama'],
            ['line' => 0, 'word' => 6, 'ipa' => 'ˈkɔnzum'],
        ]]))]);
        $draft = $this->draft(voiceover: ['ipa' => 'slash', 'ipa_auto' => true]);

        $narration = app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        // The voice gets the line with the IPA, and everything else in it as it was.
        $this->assertSame('Kruh bijeli petsto /ˈɡrama/ u trgovini /ˈkɔnzum/ za jedan euro i četrdeset devet centi.', $this->requests[0]['text']);
        $this->assertSame('Popust dvadeset pet posto. Vrijedi do tridesetog rujna.', $this->requests[1]['text']);

        // One question to OpenAI for the three slides, not one for each.
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com')));

        // What the voice was given is kept with the video, where a person who knows Croatian can check it.
        $this->assertSame($this->requests[0]['text'], $narration->describe()['clips'][0]['spoken']);
        $this->assertSame('Kruh bijeli 500 g u trgovini Konzum za 1,49 €.', $narration->describe()['script'][0]['text'], 'the script is still the text as written');
    }

    public function test_the_words_a_brand_chose_reach_the_voice_without_openai(): void
    {
        config()->set('openai.api_key', null);
        $draft = $this->draft(voiceover: ['words' => [['find' => 'Konzum', 'ipa' => "'kɔnzum"], ['find' => 'letka', 'ipa' => 'ˈlɛtka']]]);

        $narration = app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        // The default way of writing it is the tag, and the line that has no such word is as it was.
        $this->assertSame('Kruh bijeli petsto grama u trgovini <phoneme alphabet="ipa" ph="ˈkɔnzum">Konzum</phoneme> za jedan euro i četrdeset devet centi.', $this->requests[0]['text']);
        $this->assertSame('Popust dvadeset pet posto. Vrijedi do tridesetog rujna.', $this->requests[1]['text']);
        $this->assertSame('Preuzmi Listo. Dodaj prvi proizvod s <phoneme alphabet="ipa" ph="ˈlɛtka">letka</phoneme>.', $this->requests[2]['text']);
        $this->assertSame($this->requests[0]['text'], $narration->describe()['clips'][0]['spoken']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_a_brand_with_chosen_words_and_the_ipa_switched_off_is_spoken_to_as_written(): void
    {
        $draft = $this->draft(voiceover: ['ipa' => 'off', 'words' => [['find' => 'Konzum', 'ipa' => 'ˈkɔnzum']]]);

        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertStringContainsString('trgovini Konzum za', $this->requests[0]['text']);
    }

    public function test_the_style_of_the_ipa_decides_what_the_voice_receives(): void
    {
        config()->set('openai.api_key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['marks' => [['line' => 0, 'word' => 6, 'ipa' => 'ˈkɔnzum']]]))]);

        foreach (['tag', 'bare'] as $style) {
            $draft = $this->draft(voiceover: ['ipa' => $style, 'ipa_auto' => true]);
            app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));
        }

        $this->assertStringContainsString('<phoneme alphabet="ipa" ph="ˈkɔnzum">Konzum</phoneme> za', $this->requests[0]['text']);
        $this->assertStringContainsString('trgovini ˈkɔnzum za', array_column($this->requests, 'text')[3]);
    }

    public function test_the_second_video_with_the_same_words_asks_openai_nothing_and_speaks_nothing(): void
    {
        config()->set('openai.api_key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::sequence()->push(FakeOpenAi::answer(['marks' => [['line' => 0, 'word' => 6, 'ipa' => 'ˈkɔnzum']]]))]);
        $draft = $this->draft(voiceover: ['ipa' => 'slash', 'ipa_auto' => true]);
        $scenes = app(ScenePlanner::class)->forDraft($draft);

        app(Narrator::class)->narrate($draft, $scenes);
        app(Narrator::class)->narrate($draft, $scenes);

        $this->assertCount(3, $this->requests, 'three slides, said once');
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com')));
    }

    public function test_a_voice_is_not_made_without_the_ipa(): void
    {
        config()->set('openai.api_key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 401)]);
        $draft = $this->draft(voiceover: ['ipa' => 'slash', 'ipa_auto' => true]);

        try {
            app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));
            $this->fail('A line whose words could not be transcribed must not be spoken.');
        } catch (VoiceoverException $e) {
            $this->assertSame('ipa_invalid_key', $e->errorCode);
            $this->assertTrue($e->needsAttention());
        }

        $this->assertSame([], $this->requests, 'nothing was paid for at ElevenLabs');
        $this->assertSame(0, Voiceover::query()->count());
    }

    public function test_a_brand_that_turns_the_ipa_off_needs_no_openai_at_all(): void
    {
        config()->set('openai.api_key', null);
        $draft = $this->draft(voiceover: ['ipa' => 'off']);

        $narration = app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertNotNull($narration);
        $this->assertSame('Kruh bijeli petsto grama u trgovini Konzum za jedan euro i četrdeset devet centi.', $this->requests[0]['text']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_a_model_that_does_not_read_ipa_is_spoken_to_without_it_and_without_openai(): void
    {
        // The panel offers this model too, and it is not one that reads IPA: only v4 is in elevenlabs.ipa_models.
        config()->set('elevenlabs.models', ['eleven_v4' => 'v4', 'eleven_multilingual_v2' => 'Multilingual v2 (ne čita IPA)']);
        config()->set('openai.api_key', null);
        $draft = $this->draft(voiceover: ['ipa' => 'slash', 'ipa_auto' => true, 'model' => 'eleven_multilingual_v2', 'words' => [['find' => 'Konzum', 'ipa' => 'ˈkɔnzum']]]);

        $narration = app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertNotNull($narration);
        $this->assertStringNotContainsString('/', $this->requests[0]['text'], 'IPA a voice does not read would be read aloud as noise');
        $this->assertSame('eleven_multilingual_v2', $narration->model);
    }

    public function test_a_word_the_pronunciation_list_says_gets_no_ipa_while_the_brands_other_words_keep_theirs(): void
    {
        // The list says "letku" as "letka", and the brand has an IPA for "letka": the voice is given what the list says, not the IPA
        // on top of it. "Konzum" is not on the list, so it keeps its IPA.
        $draft = $this->draft(voiceover: [
            'ipa' => 'slash',
            'pronunciations' => [['find' => 'letku', 'say' => 'letka']],
            'words' => [['find' => 'letka', 'ipa' => 'ˈlɛtka'], ['find' => 'Konzum', 'ipa' => 'ˈkɔnzum']],
        ]);

        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft), ['Konzum i letku.', '', '']);

        $this->assertSame('/ˈkɔnzum/ i letka.', $this->requests[0]['text']);
    }

    public function test_a_brand_that_wants_ipa_cannot_speak_without_a_key_for_it(): void
    {
        config()->set('openai.api_key', null);
        $draft = $this->draft(voiceover: ['ipa' => 'slash', 'ipa_auto' => true]);

        try {
            app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));
            $this->fail('Writing the IPA needs OpenAI.');
        } catch (VoiceoverException $e) {
            $this->assertSame('not_configured', $e->errorCode);
            $this->assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    private function roundup(string $headline): PostDraft
    {
        $brand = Brand::factory()->create([
            'slug' => 'uselisto-'.Str::lower(Str::random(6)),
            'voice' => ['cta' => 'Preuzmi Listo'],
            'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1'],
        ]);

        $draft = PostDraft::factory()->create(['brand_id' => $brand->id, 'kind' => PostDraft::KIND_DIGEST, 'title' => $headline]);

        foreach (['Kruh bijeli 500 g', 'Mlijeko 1 l'] as $position => $title) {
            $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
                'kind' => ContentKind::Deal,
                'title' => $title,
                'subtitle' => 'Konzum',
                'price' => ['current_cents' => 149, 'old_cents' => 199, 'discount_pct' => 25, 'currency' => 'EUR', 'unit_label' => null],
                'facts' => [],
                'badges' => [],
            ]);
            $draft->contentItems()->attach($item->id, ['position' => $position, 'checksum' => $item->checksum]);
        }

        return $draft->load(['brand', 'contentItems']);
    }

    /**
     * @param  array<string, mixed>  $voiceover
     */
    private function draft(string $title = 'Kruh bijeli 500 g', ?Brand $brand = null, array $voiceover = []): PostDraft
    {
        $brand ??= Brand::factory()->create([
            'slug' => 'uselisto-'.Str::lower(Str::random(6)),
            'voice' => ['cta' => 'Preuzmi Listo', 'activation' => 'Dodaj prvi proizvod s letka.'],
            'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1', ...$voiceover],
        ]);

        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Deal,
            'title' => $title,
            'subtitle' => 'Konzum',
            'price' => ['current_cents' => 149, 'old_cents' => 199, 'discount_pct' => 25, 'currency' => 'EUR', 'unit_label' => null],
            'facts' => [['label' => 'NAJNIŽA U 30 DANA', 'value' => '1,89 €']],
            'badges' => ['−25 %'],
            'expires_at' => '2026-09-30T23:59:59Z',
        ]);
        $draft = PostDraft::factory()->create(['brand_id' => $brand->id, 'kind' => PostDraft::KIND_SINGLE, 'title' => $title]);
        $draft->contentItems()->attach($item->id, ['position' => 0, 'checksum' => $item->checksum]);

        return $draft->load(['brand', 'contentItems']);
    }
}
