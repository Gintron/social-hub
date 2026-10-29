<?php

declare(strict_types=1);

namespace Tests\Feature\Persona;

use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Models\Brand;
use App\Models\User;
use App\Persona\CharacterImages;
use App\Persona\PersonaDrafter;
use App\Persona\VoiceDesigner;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\FakeOpenAi;
use Tests\TestCase;

/**
 * A character made in the hub from an idea: the profile from OpenAI, the picture from fal (chosen from a few, refined
 * by an instruction), the voice designed on ElevenLabs and kept. The services are faked; the requests are the real ones.
 */
final class PersonaToolsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{url: string, data: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sleep::fake();
        config()->set('openai.api_key', 'test-key');
        config()->set('fal.key', 'fal-test');
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('hub.admin_emails', ['ops@example.test']);

        Http::fake(function (Request $request) {
            $this->requests[] = ['url' => $request->url(), 'data' => $request->data()];
            $url = $request->url();

            return match (true) {
                str_contains($url, 'api.openai.com') => Http::response(FakeOpenAi::answer($this->profile())),
                str_contains($url, 'fal.run/') => Http::response(['images' => array_map(
                    fn (int $i): array => ['url' => "https://v3.fal.media/files/mia-{$i}.jpg"],
                    range(1, (int) ($request->data()['num_images'] ?? 1)),
                )]),
                str_contains($url, 'fal.media') => Http::response('jpeg-bytes-'.basename($url)),
                str_contains($url, '/v1/text-to-voice/design') => Http::response(['text' => 'Hi.', 'previews' => [
                    ['generated_voice_id' => 'gen-1', 'audio_base_64' => base64_encode('mp3-1'), 'media_type' => 'audio/mpeg', 'duration_secs' => 3.1, 'language' => 'en'],
                    ['generated_voice_id' => 'gen-2', 'audio_base_64' => base64_encode('mp3-2'), 'media_type' => 'audio/mpeg', 'duration_secs' => 3.4, 'language' => 'en'],
                ]]),
                str_ends_with($url, '/v1/text-to-voice') => Http::response(['voice_id' => 'voice-mia', 'name' => 'Mia (AI lik)']),
                str_contains($url, '/v2/voices') => Http::response(['voices' => [], 'has_more' => false]),
                default => Http::response([], 404),
            };
        });
    }

    public function test_a_character_is_written_from_an_idea(): void
    {
        $fields = app(PersonaDrafter::class)->draft('A money coach for people with their first job.', 'en');

        $this->assertSame('Mia', $fields['name']);
        $this->assertSame('Warm female voice in her thirties, American accent, calm pace.', $fields['voice_description']);
        $this->assertSame('persona_profile', $this->requests[0]['data']['text']['format']['name']);
        $this->assertStringContainsString('never base them on a real or famous person', $this->requests[0]['data']['input'][0]['content']);
        $this->assertSame(['idea' => 'A money coach for people with their first job.', 'language' => 'English'], json_decode($this->requests[0]['data']['input'][1]['content'], true));
    }

    public function test_pictures_of_the_character_are_made_upright_and_kept_on_the_public_disk(): void
    {
        $paths = app(CharacterImages::class)->propose('Woman in her thirties, cream sweater', 'A bright kitchen', 'Smiling.');

        $this->assertCount(4, $paths);
        Storage::disk('public')->assertExists($paths);
        $this->assertSame('jpeg-bytes-mia-1.jpg', Storage::disk('public')->get($paths[0]));

        $ask = $this->requests[0];
        $this->assertStringEndsWith('/bytedance/seedream/v5/pro/text-to-image', $ask['url']);
        $this->assertSame('portrait_16_9', $ask['data']['image_size']);
        $this->assertStringContainsString('The person: Woman in her thirties, cream sweater.', $ask['data']['prompt']);
        $this->assertStringContainsString('Setting behind them: A bright kitchen.', $ask['data']['prompt']);

        $this->requests = [];
        $refined = app(CharacterImages::class)->refine($paths[0], 'Shorter hair.');

        $this->assertCount(2, $refined);
        $this->assertStringEndsWith('/bytedance/seedream/v5/pro/edit', $this->requests[0]['url']);
        $this->assertStringStartsWith('data:', $this->requests[0]['data']['image_urls'][0]);
        $this->assertStringStartsWith('Shorter hair.', $this->requests[0]['data']['prompt']);
    }

    public function test_a_voice_is_designed_heard_and_kept(): void
    {
        $previews = app(VoiceDesigner::class)->propose('Warm female voice in her thirties, American accent, calm pace.');

        $this->assertSame(['gen-1', 'gen-2'], array_column($previews, 'id'));
        $this->assertSame('mp3-1', Storage::disk('public')->get($previews[0]['path']));
        $this->assertSame('eleven_ttv_v3', $this->requests[0]['data']['model_id']);
        $this->assertTrue($this->requests[0]['data']['auto_generate_text']);

        $this->assertSame('voice-mia', app(VoiceDesigner::class)->keep('gen-2', 'Mia (AI lik)', 'Warm female voice.'));
        $kept = collect($this->requests)->first(fn (array $r): bool => str_ends_with($r['url'], '/v1/text-to-voice'));
        $this->assertSame(['voice_name' => 'Mia (AI lik)', 'voice_description' => 'Warm female voice.', 'generated_voice_id' => 'gen-2'], $kept['data']);
    }

    public function test_the_whole_character_is_made_in_the_brand_form(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
        $brand = Brand::factory()->create();

        $form = Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['persona.enabled' => true])
            ->callAction(TestAction::make('draftPersona')->schemaComponent('personaIdeaActions'), ['idea' => 'A money coach for young people.'])
            ->assertSchemaStateSet(['persona.name' => 'Mia', 'persona.goal' => 'Help young people save their first money.'])
            ->callAction(TestAction::make('proposeImages')->schemaComponent('personaImageActions'), ['extra' => ''])
            ->assertHasNoErrors();

        $candidates = $form->get('data.persona.image_candidates');
        $this->assertCount(4, $candidates);

        $form->set('data.persona.image_pick', $candidates[2])
            ->callAction(TestAction::make('designVoice')->schemaComponent('personaVoiceActions'))
            ->set('data.persona.voice_pick', 'gen-1')
            ->callAction(TestAction::make('keepVoice')->schemaComponent('personaVoiceKeep'))
            ->call('save')
            ->assertHasNoFormErrors();

        $brand->refresh();
        $this->assertSame($candidates[2], $brand->persona['appearance_image']);
        $this->assertSame('Mia', $brand->personaSettings()->name);
        $this->assertSame('voice-mia', $brand->voiceoverSettings()->voiceId);
        $this->assertTrue($brand->voiceover['enabled']);
        $this->assertArrayNotHasKey('image_candidates', $brand->persona);
        $this->assertArrayNotHasKey('voice_pick', $brand->persona);
    }

    /**
     * @return array<string, string>
     */
    private function profile(): array
    {
        return [
            'name' => 'Mia',
            'description' => 'A calm money coach in her thirties.',
            'goal' => 'Help young people save their first money.',
            'audience' => 'People aged 18-30',
            'tone' => 'warm, direct',
            'avoid' => 'Crypto, stock picks',
            'appearance' => 'Woman in her thirties, shoulder-length brown hair, cream sweater',
            'ambience' => 'A bright small kitchen with plants',
            'voice_description' => 'Warm female voice in her thirties, American accent, calm pace.',
        ];
    }
}
