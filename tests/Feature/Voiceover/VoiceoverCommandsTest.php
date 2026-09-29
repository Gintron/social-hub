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
            ->assertSuccessful();

        // Said again, it is not paid for again.
        $this->artisan('hub:voiceover-test', ['text' => 'Kruh 500 g za 1,49 €.', '--brand' => 'uselisto'])
            ->expectsOutputToContain('iz keša (nije naplaćeno)')
            ->assertSuccessful();

        $this->assertCount(1, $requests);
        $this->assertSame(1.1, $requests[0]['voice_settings']['speed']);
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
