<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Models\Brand;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeOpenAi;
use Tests\Support\FakeSpeech;
use Tests\TestCase;

/**
 * The commands an operator reaches for: try a voice, see what is left of the plan, clear old clips.
 */
final class VoiceoverCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        Cache::flush();
        config()->set('elevenlabs.disk', 'local');
    }

    public function test_without_a_key_the_test_command_says_what_to_set(): void
    {
        config()->set('elevenlabs.api_key', null);

        $this->artisan('hub:voiceover-test')->expectsOutputToContain('ELEVENLABS_API_KEY')->assertFailed();
    }

    public function test_it_shows_what_the_voice_receives_and_what_it_cost(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        $requests = [];
        FakeSpeech::fake($requests);
        Brand::factory()->create(['slug' => 'uselisto', 'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1', 'speed' => 1.1, 'pronunciations' => [['find' => 'Konzum', 'say' => 'Konzum']]]]);

        $this->artisan('hub:voiceover-test', ['text' => 'Kruh 500 g za 1,49 €.', '--brand' => 'uselisto'])
            ->expectsOutputToContain('Glas čita: Kruh petsto grama za jedan euro i četrdeset devet centi.')
            ->expectsOutputToContain('ElevenLabs (naplaćeno)')
            ->expectsOutputToContain('Kredita')
            ->assertSuccessful();

        // Said again, it is not paid for again.
        $this->artisan('hub:voiceover-test', ['text' => 'Kruh 500 g za 1,49 €.', '--brand' => 'uselisto'])
            ->expectsOutputToContain('iz keša (nije naplaćeno)')
            ->assertSuccessful();

        $this->assertCount(1, $requests);
        $this->assertSame(1.1, $requests[0]['voice_settings']['speed']);
    }

    public function test_compare_speaks_the_line_in_every_style_of_ipa_and_asks_openai_once(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('openai.api_key', 'test-key');
        $requests = [];
        FakeSpeech::fake($requests);
        Http::fake(['api.openai.com/*' => Http::sequence()->push(FakeOpenAi::answer(['marks' => [['line' => 0, 'word' => 5, 'ipa' => 'ˈkɔnzum']]]))]);
        Brand::factory()->create(['slug' => 'uselisto', 'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1']]);

        $this->artisan('hub:voiceover-test', ['text' => 'Kruh 500 g u trgovini Konzum.', '--brand' => 'uselisto', '--compare' => true, '--auto' => true])
            ->expectsOutputToContain('Glas čita (off): Kruh petsto grama u trgovini Konzum.')
            ->expectsOutputToContain('Glas čita (tag): Kruh petsto grama u trgovini <phoneme alphabet="ipa" ph="ˈkɔnzum">Konzum</phoneme>.')
            ->expectsOutputToContain('Glas čita (slash): Kruh petsto grama u trgovini /ˈkɔnzum/.')
            ->expectsOutputToContain('Glas čita (bare): Kruh petsto grama u trgovini ˈkɔnzum.')
            ->assertSuccessful();

        // Four clips to compare by ear; the question to OpenAI was asked once.
        $this->assertCount(4, $requests);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_contains($request->url(), 'api.openai.com')));
    }

    public function test_a_word_with_its_ipa_can_be_tried_before_it_is_saved_and_needs_no_openai(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('openai.api_key', null);
        $requests = [];
        FakeSpeech::fake($requests);
        Http::fake(['api.openai.com/*' => Http::response('', 500)]);
        Brand::factory()->create(['slug' => 'uselisto', 'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1', 'words' => [['find' => 'letka', 'ipa' => 'ˈlɛtka']]]]);

        $this->artisan('hub:voiceover-test', ['text' => 'S letka u Konzumu.', '--brand' => 'uselisto', '--compare' => true, '--word' => ['Konzumu=ˈkɔnzumu']])
            ->expectsOutputToContain('Glas čita (off): S letka u Konzumu.')
            ->expectsOutputToContain('Glas čita (slash): S /ˈlɛtka/ u /ˈkɔnzumu/.')
            ->expectsOutputToContain('Glas čita (bare): S ˈlɛtka u ˈkɔnzumu.')
            ->assertSuccessful();

        $this->assertCount(4, $requests, 'plain and the three ways of writing it');
        $this->assertStringContainsString('<phoneme alphabet="ipa" ph="ˈlɛtka">letka</phoneme>', $requests[1]['text']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_a_word_that_is_no_word_with_an_ipa_is_refused_and_a_brand_without_any_is_told_so(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        $requests = [];
        FakeSpeech::fake($requests);

        $this->artisan('hub:voiceover-test', ['text' => 'Bok.', '--voice' => 'v', '--word' => ['letka']])->expectsOutputToContain('riječ=ipa')->assertFailed();
        $this->artisan('hub:voiceover-test', ['text' => 'Bok.', '--voice' => 'v', '--word' => ['dvije riječi=ˈlɛtka']])->expectsOutputToContain('riječ=ipa')->assertFailed();
        $this->artisan('hub:voiceover-test', ['text' => 'Bok.', '--voice' => 'v'])->expectsOutputToContain('nema riječi s ručnim izgovorom')->assertSuccessful();

        $this->assertCount(1, $requests);
    }

    public function test_the_style_of_the_ipa_can_be_chosen_on_the_command_line(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('openai.api_key', 'test-key');
        $requests = [];
        FakeSpeech::fake($requests);
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['marks' => [['line' => 0, 'word' => 0, 'ipa' => 'ˈkɔnzum']]]))]);

        $this->artisan('hub:voiceover-test', ['text' => 'Konzum bijeli.', '--voice' => 'voice-cli', '--ipa' => 'bare', '--auto' => true])
            ->expectsOutputToContain('Glas čita: ˈkɔnzum bijeli.')
            ->assertSuccessful();

        $this->artisan('hub:voiceover-test', ['text' => 'Konzum bijeli.', '--voice' => 'voice-cli', '--ipa' => 'sideways'])
            ->expectsOutputToContain('--ipa')
            ->assertFailed();

        $this->assertSame(['ˈkɔnzum bijeli.'], array_column($requests, 'text'));
    }

    public function test_the_brands_choice_of_style_is_the_default_and_a_missing_openai_key_is_said_plainly(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('openai.api_key', null);
        $requests = [];
        FakeSpeech::fake($requests);
        Brand::factory()->create(['slug' => 'uselisto', 'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1', 'ipa' => 'slash', 'ipa_auto' => true]]);

        $this->artisan('hub:voiceover-test', ['text' => 'Konzum bijeli.', '--brand' => 'uselisto'])
            ->expectsOutputToContain('OPENAI_API_KEY nije postavljen. [ipa_not_configured]')
            ->assertFailed();

        $this->assertSame([], $requests, 'nothing is spoken that could not be checked');
    }

    public function test_the_command_says_when_the_model_would_not_read_the_ipa(): void
    {
        // The panel offers this model too, and it is not one that reads IPA: only v4 is in elevenlabs.ipa_models.
        config()->set('elevenlabs.models', ['eleven_v4' => 'v4', 'eleven_multilingual_v2' => 'Multilingual v2 (ne čita IPA)']);
        config()->set('elevenlabs.api_key', 'test-key');
        $requests = [];
        FakeSpeech::fake($requests);
        Brand::factory()->create(['slug' => 'uselisto', 'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1', 'model' => 'eleven_multilingual_v2', 'ipa' => 'slash', 'words' => [['find' => 'Konzum', 'ipa' => 'ˈkɔnzum']]]]);

        $this->artisan('hub:voiceover-test', ['text' => 'Konzum bijeli.', '--brand' => 'uselisto'])
            ->expectsOutputToContain('ne čita IPA u tekstu')
            ->expectsOutputToContain('Glas čita: Konzum bijeli.')
            ->assertSuccessful();

        $this->assertSame(['Konzum bijeli.'], array_column($requests, 'text'));
    }

    public function test_the_doctor_names_the_brands_whose_model_does_not_read_ipa(): void
    {
        // The panel offers this model too, and it is not one that reads IPA: only v4 is in elevenlabs.ipa_models.
        config()->set('elevenlabs.models', ['eleven_v4' => 'v4', 'eleven_multilingual_v2' => 'Multilingual v2 (ne čita IPA)']);
        config()->set('elevenlabs.api_key', null);
        Brand::factory()->create(['slug' => 'uselisto', 'voiceover' => ['enabled' => true, 'voice_id' => 'v', 'model' => 'eleven_multilingual_v2', 'ipa' => 'slash', 'words' => [['find' => 'Konzum', 'ipa' => 'ˈkɔnzum']]]]);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('hub:doctor', ['--skip-render' => true])->expectsOutputToContain('model ne čita IPA u tekstu (samo eleven_v4), pa se izgovor ne šalje: uselisto (eleven_multilingual_v2)');
    }

    public function test_a_voice_id_on_the_command_line_stands_in_for_the_brands(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        $requests = [];
        FakeSpeech::fake($requests);

        $this->artisan('hub:voiceover-test', ['text' => 'Bok.', '--voice' => 'voice-cli'])->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/text-to-speech/voice-cli'));
    }

    public function test_without_any_voice_it_says_how_to_find_one(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');

        $this->artisan('hub:voiceover-test', ['text' => 'Bok.'])->expectsOutputToContain('--list-voices')->assertFailed();
    }

    public function test_it_lists_the_accounts_voices(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        Http::fake(['api.elevenlabs.io/v2/voices*' => Http::response(['voices' => [
            ['voice_id' => 'v-a', 'name' => 'Ana', 'category' => 'professional', 'labels' => [], 'verified_languages' => [['language' => 'hr']]],
        ], 'has_more' => false])]);

        $this->artisan('hub:voiceover-test', ['--list-voices' => true])->expectsOutputToContain('★ Ana')->assertSuccessful();
    }

    public function test_a_refusal_from_the_api_is_reported_not_thrown(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'voice_not_found', 'message' => 'No such voice']], 404)]);

        $this->artisan('hub:voiceover-test', ['text' => 'Bok.', '--voice' => 'nope'])->expectsOutputToContain('voice_not_found')->assertFailed();
    }

    public function test_old_clips_are_deleted_from_disk_and_their_records_stay(): void
    {
        $old = Voiceover::factory()->create(['path' => 'voiceovers/test/old.mp3', 'bytes' => 5, 'updated_at' => now()->subDays(90)]);
        $recent = Voiceover::factory()->create(['path' => 'voiceovers/test/recent.mp3', 'bytes' => 5, 'updated_at' => now()->subDays(3)]);
        Storage::disk('local')->put($old->path, 'audio');
        Storage::disk('local')->put($recent->path, 'audio');

        $this->artisan('hub:prune-voiceovers', ['--days' => 30])->expectsOutputToContain('Obrisano 1 zapisa')->assertSuccessful();

        Storage::disk('local')->assertMissing($old->path);
        Storage::disk('local')->assertExists($recent->path);
        $this->assertSame(2, Voiceover::query()->count(), 'what was said and paid for stays on record');
        $this->assertFalse($old->refresh()->fileExists());
    }

    public function test_the_doctor_says_what_is_missing_for_the_voice(): void
    {
        config()->set('elevenlabs.api_key', null);
        Brand::factory()->create(['slug' => 'uselisto', 'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1']]);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('hub:doctor', ['--skip-render' => true])
            ->expectsOutputToContain('ELEVENLABS_API_KEY not set')
            // One row per check: this is the brand that asked for a voice it cannot have.
            ->expectsOutputToContain('bez glasa ili ključa (videi ostaju bez glasa): uselisto');
    }

    public function test_the_doctor_reports_the_plan_when_the_key_works(): void
    {
        config()->set('elevenlabs.api_key', 'test-key');
        Http::fake([
            'api.elevenlabs.io/v1/user/subscription' => Http::response(['character_count' => 12_500, 'character_limit' => 100_000, 'tier' => 'creator', 'status' => 'active']),
            '*' => Http::response('', 200),
        ]);

        $this->artisan('hub:doctor', ['--skip-render' => true])->expectsOutputToContain('creator, 12.500 od 100.000 znakova iskorišteno');
    }
}
