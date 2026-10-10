<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Actions\CreateDraft;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Filament\Pages\PostPerformance;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\PostDrafts\Pages\EditPostDraft;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Support\VoiceoverPanel;
use App\Jobs\RenderVideoJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Models\PostMetric;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use App\Models\Voiceover;
use App\Voiceover\Ipa;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\FakeOpenAi;
use Tests\Support\FakeSpeech;
use Tests\TestCase;

/**
 * Where a person turns the voice on and off: the brand, the auto-publish rule, the channel on the review
 * screen — and what each of them is told when the voice cannot work.
 */
final class VoiceoverPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
        Storage::fake('local');
        Storage::fake('public');
        Sleep::fake();
        Cache::flush();
        config()->set('elevenlabs.api_key', null);
        config()->set('elevenlabs.disk', 'local');
        config()->set('hub.media_disk', 'public');
    }

    public function test_the_brand_form_says_when_there_is_no_key_and_still_saves(): void
    {
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->assertOk()
            ->assertSee('Voice-over (ElevenLabs)')
            ->assertSee('ELEVENLABS_API_KEY')
            ->assertSee('Popis glasova se ne može učitati')
            ->fillForm(['voiceover.enabled' => true, 'voiceover.voice_id' => 'voice-manual'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = $brand->refresh()->voiceoverSettings();
        $this->assertTrue($settings->enabled);
        $this->assertSame('voice-manual', $settings->voiceId);
        $this->assertFalse($settings->canSpeak(), 'a chosen voice is not enough without a key');
    }

    public function test_the_brand_form_lists_the_accounts_voices_and_what_is_left_of_the_plan(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        Http::fake([
            'api.elevenlabs.io/v2/voices*' => Http::response(['voices' => [
                ['voice_id' => 'v-b', 'name' => 'Bruno', 'category' => 'premade', 'labels' => [], 'verified_languages' => []],
                ['voice_id' => 'v-a', 'name' => 'Ana', 'category' => 'professional', 'labels' => [], 'verified_languages' => [['language' => 'hr']]],
            ], 'has_more' => false]),
            'api.elevenlabs.io/v1/user/subscription' => Http::response(['character_count' => 12_500, 'character_limit' => 100_000, 'next_character_count_reset_unix' => 1_790_000_000, 'tier' => 'creator', 'status' => 'active']),
        ]);
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->assertOk()
            ->assertSee('ElevenLabs je spojen')
            ->assertSee('plan creator')
            ->assertSee('87.500 od 100.000 znakova')
            ->assertSee('★ Ana')
            ->assertSee('Bruno')
            ->assertDontSee('Popis glasova se ne može učitati');
    }

    public function test_an_api_that_is_down_costs_the_form_nothing_after_the_first_look(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        Http::fake(['api.elevenlabs.io/*' => Http::response('down', 503)]);
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])->assertOk()->assertSee('Popis glasova se ne može učitati');
        $sent = count(Http::recorded());

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])->assertOk()->assertSee('Popis glasova se ne može učitati');

        $this->assertSame($sent, count(Http::recorded()), 'a failure is remembered for a minute instead of being asked again on every render');
    }

    public function test_the_whole_narrator_is_saved_and_read_back_by_the_hub(): void
    {
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm([
                'voiceover.enabled' => true,
                'voiceover.voice_id' => 'voice-manual',
                'voiceover.model' => 'eleven_v4',
                'voiceover.speed' => 1.1,
                'voiceover.music' => 'quiet',
                'voiceover.outro' => 'Preuzmi Listo besplatno.',
                'voiceover.ipa' => 'bare',
                'voiceover.ipa_auto' => true,
                'voiceover.words' => [['find' => 'letka', 'ipa' => "'lɛtka"], ['find' => 'Letak', 'ipa' => '/ˈlɛːtak/']],
                'voiceover.pronunciations' => [['find' => 'SPAR', 'say' => 'Spar'], ['find' => 'DM', 'say' => 'de em']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = $brand->refresh()->voiceoverSettings();
        $this->assertSame('eleven_v4', $settings->model);
        $this->assertSame(1.1, $settings->speed);
        $this->assertSame('bare', $settings->ipa);
        $this->assertTrue($settings->ipaAuto);
        $this->assertSame(['letka' => 'ˈlɛtka', 'Letak' => 'ˈlɛːtak'], $settings->words, 'the IPA is kept the way it will be sent');
        $this->assertSame(-20.0, $settings->musicGainDb());
        $this->assertSame('Preuzmi Listo besplatno.', $settings->outro);
        $this->assertSame(['SPAR' => 'Spar', 'DM' => 'de em'], $settings->pronunciations);
    }

    public function test_a_voice_can_be_heard_before_it_is_saved_and_shows_how_the_numbers_are_read(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        $requests = [];
        FakeSpeech::fake($requests);
        Http::fake(['*' => Http::response('', 404)]);
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['voiceover.voice_id' => 'voice-unsaved', 'voiceover.speed' => 1.05])
            ->callAction(TestAction::make('previewVoiceover')->schemaComponent('voiceoverActions'), ['text' => 'Kruh 500 g za 1,49 €.'])
            ->assertNotified('Probni zapis je spreman');

        $this->assertSame('Kruh petsto grama za jedan euro i četrdeset devet centi.', $requests[0]['text']);
        $this->assertSame(1.05, $requests[0]['voice_settings']['speed']);
        $this->assertNull($brand->refresh()->voiceover, 'listening does not save anything');

        // The sample is reached through the panel's login, not from a public disk.
        $clip = Voiceover::query()->firstOrFail();
        $this->get(route('voiceovers.audio', $clip))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    }

    public function test_an_ipa_word_can_be_heard_from_its_unsaved_brand_form_row(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('openai.api_key', null);
        $requests = [];
        FakeSpeech::fake($requests);
        Http::fake(['*' => Http::response('', 404)]);
        $brand = Brand::factory()->create();

        $component = Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm([
                'voiceover.voice_id' => 'voice-unsaved',
                'voiceover.model' => 'eleven_v4',
                'voiceover.ipa_auto' => true,
                'voiceover.words' => [['find' => 'Prelistaj', 'ipa' => 'prɛːˈlistaj']],
            ]);

        $rows = $component->get('data.voiceover.words');
        $itemKey = array_key_first($rows);

        $component
            ->callFormComponentAction(
                'voiceoverWords',
                TestAction::make('previewIpa')->arguments(['item' => $itemKey]),
            )
            ->assertNotified('Probni IPA zapis je spreman');

        $this->assertCount(1, $requests);
        $this->assertSame('/prɛːˈlistaj/', $requests[0]['text']);
        $this->assertSame('eleven_v4', $requests[0]['model_id']);
        $this->assertNull($brand->refresh()->voiceover, 'listening to an unsaved IPA row does not save brand settings');

        $clip = Voiceover::query()->firstOrFail();
        $this->get(route('voiceovers.audio', $clip))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    }

    public function test_a_voice_is_heard_the_way_a_video_would_hear_it_with_the_ipa_in_the_text(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('openai.api_key', 'test-key');
        $requests = [];
        FakeSpeech::fake($requests);
        Http::fake([
            'api.openai.com/*' => Http::response(FakeOpenAi::answer(['marks' => [['line' => 0, 'word' => 2, 'ipa' => 'ˈkɔnzumu']]])),
            '*' => Http::response('', 404),
        ]);
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['voiceover.voice_id' => 'voice-unsaved', 'voiceover.ipa' => 'slash', 'voiceover.ipa_auto' => true])
            ->callAction(TestAction::make('previewVoiceover')->schemaComponent('voiceoverActions'), ['text' => 'Kruh u Konzumu.'])
            ->assertNotified('Probni zapis je spreman');

        // The sample is spoken from the line as a video's would be: the IPA is in it.
        $this->assertCount(1, $requests);
        $this->assertSame('Kruh u /ˈkɔnzumu/.', $requests[0]['text']);
    }

    public function test_a_voice_cannot_be_heard_with_the_ipa_on_and_no_key_for_it_and_the_form_says_so(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('openai.api_key', null);
        Http::fake(['*' => Http::response('', 404)]);
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['voiceover.voice_id' => 'voice-unsaved', 'voiceover.ipa' => 'slash', 'voiceover.ipa_auto' => true])
            ->assertSee('Riječi s ručnim izgovorom (IPA)')
            ->callAction(TestAction::make('previewVoiceover')->schemaComponent('voiceoverActions'), ['text' => 'Bok'])
            ->assertNotified('Glas nije izgovoren');

        // The form itself looks at ElevenLabs (voices, plan); a sample is neither asked for nor spoken.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'text-to-speech') || str_contains($request->url(), 'api.openai.com'));
    }

    public function test_a_voice_is_heard_with_the_chosen_words_and_without_them(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('openai.api_key', null);
        $requests = [];
        FakeSpeech::fake($requests);
        Http::fake(['*' => Http::response('', 404)]);
        $brand = Brand::factory()->create();

        $form = Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['voiceover.voice_id' => 'voice-unsaved', 'voiceover.words' => [['find' => 'letka', 'ipa' => 'ˈlɛtka']]]);

        $form->callAction(TestAction::make('previewVoiceover')->schemaComponent('voiceoverActions'), ['text' => 'S letka ravno.'])
            ->assertNotified('Probni zapis je spreman');
        $form->callAction(TestAction::make('previewVoiceover')->schemaComponent('voiceoverActions'), ['text' => 'S letka ravno.', 'without_ipa' => true])
            ->assertNotified('Probni zapis je spreman');

        $this->assertSame(['S <phoneme alphabet="ipa" ph="ˈlɛtka">letka</phoneme> ravno.', 'S letka ravno.'], array_column($requests, 'text'));
        $this->assertNull($brand->refresh()->voiceover, 'listening does not save anything');
    }

    public function test_a_model_may_suggest_the_ipa_of_a_word_and_never_fills_in_what_is_not_one(): void
    {
        config()->set('openai.api_key', 'test-key');
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(FakeOpenAi::answer(['ipa' => "'lɛtka"]))
            ->push(FakeOpenAi::answer(['ipa' => 'ˈmatʃku']))
            ->push(FakeOpenAi::answer(['ipa' => '<b>ˈlɛtka</b>']))]);

        $this->assertSame(['ipa' => 'ˈlɛtka', 'error' => null], VoiceoverPanel::suggestIpa('letka'));
        $this->assertNull(VoiceoverPanel::suggestIpa('listu')['ipa'], 'the IPA of another word is refused');
        $this->assertStringContainsString('Upiši ga rukom', (string) VoiceoverPanel::suggestIpa('letka')['error']);
        $this->assertStringContainsString('Upiši prvo riječ', (string) VoiceoverPanel::suggestIpa('dvije riječi')['error']);
        $this->assertStringContainsString('Upiši prvo riječ', (string) VoiceoverPanel::suggestIpa('')['error']);

        config()->set('openai.api_key', null);
        $this->assertStringContainsString('OPENAI_API_KEY', (string) VoiceoverPanel::suggestIpa('letka')['error']);
    }

    public function test_a_row_of_words_needs_one_word_and_its_ipa(): void
    {
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['voiceover.voice_id' => 'voice-1', 'voiceover.words' => [['find' => 'dvije riječi', 'ipa' => 'ˈlɛtka']]])
            ->call('save')
            ->assertHasFormErrors();
    }

    public function test_the_status_line_says_what_the_ipa_does_and_when_a_key_is_missing(): void
    {
        config()->set('elevenlabs.api_key', null);
        config()->set('openai.api_key', null);
        config()->set('openai.model', 'gpt-6-sol');
        config()->set('openai.ipa.model', null);
        $words = [['find' => 'letka', 'ipa' => 'ˈlɛtka']];

        $this->assertStringContainsString('Izgovor (IPA) je isključen', VoiceoverPanel::status(Ipa::OFF));
        $this->assertStringContainsString('Izgovor: 0 ručnih riječi.', VoiceoverPanel::status(Ipa::TAG));
        $this->assertStringContainsString('Izgovor: 1 ručnih riječi.', VoiceoverPanel::status(Ipa::TAG, 'eleven_v4', $words));
        // Only a model that is asked to write IPA needs a key for it.
        $this->assertStringContainsString('OPENAI_API_KEY', VoiceoverPanel::status(Ipa::SLASH, 'eleven_v4', $words, true));

        config()->set('openai.api_key', 'test-key');
        config()->set('openai.ipa.model', 'gpt-6-astra');

        $this->assertStringContainsString('OpenAI (gpt-6-astra) piše IPA i za ostale', VoiceoverPanel::status(Ipa::SLASH, 'eleven_v4', $words, true));
        // What the form has not chosen is what the environment says (the tag, with no model asked, in the tests).
        $this->assertStringContainsString('Izgovor: 1 ručnih riječi.', VoiceoverPanel::status('', null, $words));
    }

    public function test_the_status_line_says_when_the_model_does_not_read_the_ipa(): void
    {
        // The panel offers this model too, and it is not one that reads IPA: only v4 is in elevenlabs.ipa_models.
        config()->set('elevenlabs.models', ['eleven_v4' => 'v4', 'eleven_multilingual_v2' => 'Multilingual v2 (ne čita IPA)']);
        config()->set('elevenlabs.api_key', null);
        $words = [['find' => 'letka', 'ipa' => 'ˈlɛtka']];

        $this->assertStringContainsString('ne čita IPA u tekstu (samo eleven_v4), pa se izgovor ne šalje', VoiceoverPanel::status(Ipa::TAG, 'eleven_multilingual_v2', $words));
        $this->assertStringNotContainsString('ne čita IPA', VoiceoverPanel::status(Ipa::TAG, 'eleven_multilingual_v2', []), 'no words, nothing to be ignored');
        $this->assertStringContainsString('Izgovor: 1 ručnih riječi.', VoiceoverPanel::status(Ipa::TAG, 'eleven_v4', $words));
        $this->assertStringContainsString('Izgovor: 1 ručnih riječi.', VoiceoverPanel::status(Ipa::TAG, '', $words), 'the default model reads it');
    }

    public function test_only_a_hub_admin_can_listen_to_a_clip(): void
    {
        $clip = Voiceover::factory()->create(['path' => 'voiceovers/test/clip.mp3']);
        Storage::disk('local')->put($clip->path, 'audio');

        $this->get(route('voiceovers.audio', $clip))->assertOk();

        $this->actingAs(User::factory()->create(['email' => 'someone@example.test']));
        $this->get(route('voiceovers.audio', $clip))->assertForbidden();

        auth()->logout();
        $this->get(route('voiceovers.audio', $clip))->assertRedirect();
    }

    public function test_a_clip_that_is_gone_from_disk_is_not_found(): void
    {
        $clip = Voiceover::factory()->create(['path' => 'voiceovers/test/gone.mp3']);

        $this->get(route('voiceovers.audio', $clip))->assertNotFound();
    }

    public function test_a_voice_that_cannot_be_heard_says_why_instead_of_failing_the_page(): void
    {
        $brand = Brand::factory()->create();

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['voiceover.voice_id' => 'voice-unsaved'])
            ->callAction(TestAction::make('previewVoiceover')->schemaComponent('voiceoverActions'), ['text' => 'Bok'])
            ->assertNotified('Glas nije izgovoren');
    }

    public function test_an_auto_publish_rule_carries_the_choice_to_the_channel(): void
    {
        $brand = Brand::factory()->create();
        $source = Source::factory()->for($brand)->create();
        $rule = $source->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true]);

        Livewire::test(EditSource::class, ['record' => $source->getRouteKey()])
            ->assertOk()
            ->assertSee('Voice-over')
            ->fillForm(['autoPublishRules' => ["record-{$rule->getKey()}" => [
                'platform' => Platform::TikTok->value, 'enabled' => true, 'delay_minutes' => 0, 'settings' => ['delivery' => 'direct', 'voiceover' => 'off'],
            ]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('off', $rule->refresh()->settings['voiceover']);
        $this->assertSame('off', $rule->channelOptions()['settings']['voiceover']);
    }

    public function test_the_review_screen_shows_the_voice_per_video_channel_and_turning_it_off_renders_that_channel_again(): void
    {
        Queue::fake();
        config()->set('elevenlabs.api_key', 'test-key');
        [$draft, $tiktok, $page] = $this->draft(['enabled' => true, 'voice_id' => 'voice-1']);

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertOk()
            ->assertSet("data.channels.v{$tiktok->id}.voiceover", true)
            ->assertSet("data.channels.v{$page->id}.voiceover", false)
            ->set("data.channels.v{$tiktok->id}.voiceover", false);

        $this->assertSame('off', $tiktok->refresh()->setting('voiceover'));
        $this->assertNull($page->refresh()->setting('voiceover'), 'the other channel is left alone');
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$tiktok->id] && ! $job->voiceover);
    }

    public function test_a_brand_without_a_voice_cannot_be_given_one_from_the_review_screen(): void
    {
        Queue::fake();
        [$draft, $tiktok] = $this->draft(['enabled' => true, 'voice_id' => null]);

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertSee('brend nema odabran glas')
            ->assertSet("data.channels.v{$tiktok->id}.voiceover", false);

        Queue::assertNothingPushed();
    }

    public function test_the_render_dialog_offers_the_script_written_from_the_item_and_sends_it_only_when_it_was_changed(): void
    {
        Queue::fake();
        config()->set('elevenlabs.api_key', 'test-key');
        [$draft, $tiktok] = $this->draft(['enabled' => true, 'voice_id' => 'voice-1']);

        $component = Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->mountAction(TestAction::make("render_{$tiktok->id}")->schemaComponent("mediaActions{$tiktok->id}"))
            ->assertMountedActionModalSee(['Voice-over', 'Što glas govori', 'Udica', 'Završni poziv']);

        // One row per slide, written from the item: the hook, the card, the brand's closing line.
        $rows = array_values($component->instance()->mountedActions[0]['data']['script']);
        $this->assertSame(['Udica', 'Kartica', 'Završni poziv'], array_column($rows, 'label'));
        $this->assertSame('Kruh bijeli 500 g u trgovini Konzum za 1,49 €.', $rows[0]['text']);
        $this->assertSame('Popust 25 %. Vrijedi do 31.12.2026.', $rows[1]['text']);
        $this->assertSame('Preuzmi Listo. Dodaj prvi proizvod s letka.', $rows[2]['text']);
        $this->assertTrue($component->instance()->mountedActions[0]['data']['voiceover']);

        // Left as it was written: the script is written again from the item at render time.
        $component->callMountedAction();
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->voiceover && $job->script === null);

        Queue::fake();
        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->callAction(TestAction::make("render_{$tiktok->id}")->schemaComponent("mediaActions{$tiktok->id}"), [
                'seconds' => 3, 'audio' => 'auto', 'motion' => true, 'voiceover' => true,
                'script' => [
                    ['label' => 'Udica', 'text' => 'Pogledaj ovu akciju.'],
                    ['label' => 'Kartica', 'text' => ''],
                    ['label' => 'Završni poziv', 'text' => 'Preuzmi Listo.'],
                ],
            ]);

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->script === ['Pogledaj ovu akciju.', '', 'Preuzmi Listo.']);
    }

    public function test_a_script_with_an_invented_price_is_refused_in_the_dialog(): void
    {
        Queue::fake();
        config()->set('elevenlabs.api_key', 'test-key');
        [$draft, $tiktok] = $this->draft(['enabled' => true, 'voice_id' => 'voice-1']);

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->callAction(TestAction::make("render_{$tiktok->id}")->schemaComponent("mediaActions{$tiktok->id}"), [
                'seconds' => 3, 'audio' => 'auto', 'motion' => true, 'voiceover' => true,
                'script' => [['label' => 'Udica', 'text' => 'Samo za 0,01 €!'], ['label' => 'Kartica', 'text' => ''], ['label' => 'Završni poziv', 'text' => '']],
            ])
            ->assertNotified('Render nije pokrenut');

        Queue::assertNothingPushed();
    }

    public function test_the_channel_remembers_the_voice_it_was_asked_for_only_when_the_render_was_accepted(): void
    {
        Queue::fake();
        config()->set('elevenlabs.api_key', 'test-key');
        [$draft, $tiktok] = $this->draft(['enabled' => false, 'voice_id' => 'voice-1']);
        $options = ['seconds' => 3, 'audio' => 'auto', 'motion' => true, 'voiceover' => true];

        // Asked for with a script the offer does not support: refused, and nothing is remembered.
        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->callAction(TestAction::make("render_{$tiktok->id}")->schemaComponent("mediaActions{$tiktok->id}"), [...$options, 'script' => [
                ['label' => 'Udica', 'text' => 'Samo za 0,01 €!'], ['label' => 'Kartica', 'text' => ''], ['label' => 'Završni poziv', 'text' => ''],
            ]]);
        $this->assertNull($tiktok->refresh()->setting('voiceover'));
        Queue::assertNothingPushed();

        // Asked for properly: rendered with the voice, and the channel keeps it for the next render.
        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->callAction(TestAction::make("render_{$tiktok->id}")->schemaComponent("mediaActions{$tiktok->id}"), [...$options, 'script' => array_map(
                fn (array $row): array => $row,
                [['label' => 'Udica', 'text' => 'Kruh bijeli 500 g u trgovini Konzum za 1,49 €.'], ['label' => 'Kartica', 'text' => 'Popust 25 %. Vrijedi do 31.12.2026.'], ['label' => 'Završni poziv', 'text' => 'Preuzmi Listo. Dodaj prvi proizvod s letka.']],
            )]);

        $this->assertSame('on', $tiktok->refresh()->setting('voiceover'));
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->voiceover && $job->script === null, 'a script that is exactly what would be written is not frozen');
    }

    public function test_a_video_whose_voice_failed_says_so_on_the_screen_and_a_narrated_one_shows_what_it_says(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        [$draft, $tiktok] = $this->draft(['enabled' => true, 'voice_id' => 'voice-1']);
        $asset = MediaAsset::factory()->for($draft->brand)->create([
            'post_draft_id' => $draft->id, 'template_key' => 'video/slideshow', 'format' => 'mp4', 'width' => 1080, 'height' => 1920, 'duration_ms' => 9000,
            'params' => ['voiceover' => ['status' => 'failed', 'code' => 'quota_exceeded', 'error' => 'ElevenLabs 401 (quota_exceeded): nema znakova']],
        ]);
        $tiktok->media()->sync([$asset->id => ['position' => 0]]);

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertSee('Video je bez voice-overa')
            ->assertSee('nema znakova');

        $asset->update(['params' => ['voiceover' => ['status' => 'ok', 'characters' => 96, 'script' => [
            ['role' => 'hook', 'text' => 'Kruh bijeli za 1,49 €.'], ['role' => 'card', 'text' => ''], ['role' => 'closing', 'text' => 'Preuzmi Listo.'],
        ], 'clips' => [
            ['voiceover_id' => 1, 'seconds' => 3.1, 'spoken' => 'Kruh bijeli za jedan euro i četrdeset devet centi.'], null, ['voiceover_id' => 2, 'seconds' => 2.0, 'spoken' => 'Preuzmi Lísto.'],
        ]]]]);

        Livewire::test(EditPostDraft::class, ['record' => $draft->getRouteKey()])
            ->assertDontSee('Video je bez voice-overa')
            ->assertSee('Voice-over · 96 znakova')
            ->assertSee('Kruh bijeli za 1,49 €.')
            ->assertSee('slajd samo uz glazbu')
            // What the voice was given, with its numbers spelled out and the IPA of its words put in: where a wrong transcription is caught.
            ->assertSee('Glas čita: Preuzmi Lísto.')
            ->assertSee('Glas čita: Kruh bijeli za jedan euro i četrdeset devet centi.');
    }

    public function test_the_performance_page_compares_narrated_and_plain_videos_once_there_is_something_to_compare(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');

        // Only plain videos measured: nothing to compare, so no table for it.
        [$plainDraft, $plain] = $this->publishedVideo(['status' => 'none'], views: 300);
        $this->get(PostPerformance::getUrl())->assertOk()->assertDontSee('Voice-over (samo videi)');

        [$voicedDraft, $voiced] = $this->publishedVideo(['status' => 'ok'], views: 900);
        $this->assertNotSame($plainDraft->id, $voicedDraft->id);
        $this->assertNotSame($plain->id, $voiced->id);

        $this->get(PostPerformance::getUrl())
            ->assertOk()
            ->assertSee('Voice-over (samo videi)')
            ->assertSee('Video s glasom')
            ->assertSee('Video bez glasa');
    }

    /**
     * @param  array<string, mixed>  $voiceover
     * @return array{0: PostDraft, 1: PostVariant}
     */
    private function publishedVideo(array $voiceover, int $views): array
    {
        [$draft, $tiktok] = $this->draft(['enabled' => true, 'voice_id' => 'voice-1']);
        $asset = MediaAsset::factory()->for($draft->brand)->create([
            'post_draft_id' => $draft->id, 'template_key' => 'video/slideshow', 'format' => 'mp4', 'width' => 1080, 'height' => 1920, 'duration_ms' => 9000,
            'params' => ['voiceover' => $voiceover],
        ]);
        $tiktok->media()->sync([$asset->id => ['position' => 0]]);
        $tiktok->forceFill(['status' => VariantStatus::Published, 'external_post_id' => 'x'.$draft->id, 'published_at' => now()->subDays(2)])->save();
        PostMetric::query()->create(['post_variant_id' => $tiktok->id, 'captured_at' => now(), 'views' => $views, 'reach' => $views, 'likes' => 3]);

        return [$draft, $tiktok];
    }

    /**
     * @param  array<string, mixed>  $voiceover
     * @return array{0: PostDraft, 1: PostVariant, 2: PostVariant}
     */
    private function draft(array $voiceover): array
    {
        $brand = Brand::factory()->create([
            'voice' => ['cta' => 'Preuzmi Listo', 'activation' => 'Dodaj prvi proizvod s letka.'],
            'voiceover' => $voiceover,
        ]);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Deal,
            'title' => 'Kruh bijeli 500 g',
            'subtitle' => 'Konzum',
            'price' => ['current_cents' => 149, 'old_cents' => 199, 'discount_pct' => 25, 'currency' => 'EUR', 'unit_label' => null],
            'facts' => [],
            'badges' => [],
            'expires_at' => '2026-12-31T23:59:59Z',
        ]);

        $draft = app(CreateDraft::class)->execute($item, [
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::FacebookPage]),
        ], status: DraftStatus::PendingApproval, render: false);

        return [
            $draft,
            $draft->variants->firstWhere('platform', Platform::TikTok),
            $draft->variants->firstWhere('platform', Platform::FacebookPage),
        ];
    }
}
