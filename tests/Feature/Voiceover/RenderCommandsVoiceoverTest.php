<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeSpeech;
use Tests\Support\MakesVideoFixtures;
use Tests\TestCase;

/**
 * The by-hand ways to a video — `hub:render-video` and a campaign storyboard — get the same voice the
 * automatic ones do. Real Chromium and ffmpeg; only ElevenLabs is faked.
 */
#[Group('render')]
final class RenderCommandsVoiceoverTest extends TestCase
{
    use MakesVideoFixtures;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $chrome = (string) config('hub.render.chrome_path');

        if ($chrome !== '' && ! is_file($chrome)) {
            $this->markTestSkipped("No Chromium at {$chrome}");
        }

        Storage::fake('public');
        Storage::fake('local');
        Sleep::fake();
        config()->set('hub.media_disk', 'public');
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('elevenlabs.disk', 'local');
        FakeSpeech::fake($this->requests);
        Http::fake(['*' => Http::response('', 404)]);

        $this->directory = sys_get_temp_dir().'/storyboard-'.uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_render_video_can_narrate_the_items_it_tells(): void
    {
        $brand = $this->brand();
        ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Deal, 'title' => 'Kruh bijeli 500 g', 'subtitle' => 'Konzum', 'facts' => [], 'badges' => [], 'images' => [],
            'price' => ['current_cents' => 149, 'old_cents' => 199, 'discount_pct' => 25, 'currency' => 'EUR', 'unit_label' => null],
        ]);

        $this->artisan('hub:render-video', ['--brand' => 'uselisto', '--kind' => 'deal', '--count' => 1, '--voiceover' => true, '--no-motion' => true])
            ->expectsOutputToContain('Voice-over:')
            ->assertSuccessful();

        $this->assertCount(3, $this->requests);
        $this->assertSame('Kruh bijeli petsto grama u trgovini Konzum za jedan euro i četrdeset devet centi.', $this->requests[0]['text']);
    }

    public function test_render_video_without_the_flag_is_the_video_it_always_was(): void
    {
        $brand = $this->brand();
        ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['kind' => ContentKind::Deal, 'facts' => [], 'images' => []]);

        $this->artisan('hub:render-video', ['--brand' => 'uselisto', '--kind' => 'deal', '--count' => 1, '--no-motion' => true])->assertSuccessful();

        $this->assertSame([], $this->requests);
    }

    public function test_a_voice_asked_for_that_cannot_be_made_stops_the_command(): void
    {
        $brand = $this->brand();
        ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['kind' => ContentKind::Deal, 'facts' => [], 'images' => []]);
        $brand->update(['voiceover' => ['enabled' => true, 'voice_id' => null]]);

        $this->artisan('hub:render-video', ['--brand' => 'uselisto', '--kind' => 'deal', '--count' => 1, '--voiceover' => true])
            ->expectsOutputToContain('brend nema odabran glas')
            ->assertFailed();
    }

    public function test_a_storyboard_slide_with_a_say_is_spoken_and_the_others_are_left_to_the_music(): void
    {
        $this->brand();
        $manifest = $this->directory.'/scenario.json';
        file_put_contents($manifest, json_encode([
            'voice' => ['cta' => 'Preuzmi Listo'],
            'slides' => [
                ['template' => 'kinds/feature-story', 'seconds' => 2.0, 'say' => 'Još prepisuješ cijene iz letka?', 'data' => ['title' => 'Još prepisuješ cijene iz letka?', 'theme' => 'light']],
                ['template' => 'kinds/feature-story', 'seconds' => 2.0, 'data' => ['title' => 'Lista sama zbraja.', 'theme' => 'light']],
                ['template' => 'kinds/cta-story', 'seconds' => 3.5, 'say' => 'Preuzmi Listo. 20 dana bez kartice.', 'data' => []],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('hub:render-storyboard', ['manifest' => $manifest, '--brand' => 'uselisto', '--output' => $this->directory.'/out'])
            ->expectsOutputToContain('Voice-over: ')
            ->assertSuccessful();

        $this->assertSame(['Još prepisuješ cijene iz letka?', 'Preuzmi Listo. Dvadeset dana bez kartice.'], array_column($this->requests, 'text'));
        $video = $this->directory.'/out/video.mp4';
        $this->assertFileExists($video);
        $render = json_decode((string) file_get_contents($this->directory.'/out/render.json'), true);
        $this->assertSame('ok', $render['params']['voiceover']['status']);
        $this->assertGreaterThan(-35.0, $this->level($video, 0.4, 1.5), 'the first line is heard');

        @unlink($this->directory.'/out/video.mp4');
        foreach (glob($this->directory.'/out/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory.'/out');
    }

    public function test_a_storyboard_without_any_say_is_as_silent_as_before(): void
    {
        $this->brand();
        $manifest = $this->directory.'/scenario.json';
        file_put_contents($manifest, json_encode(['slides' => [
            ['template' => 'kinds/feature-story', 'seconds' => 2.0, 'data' => ['title' => 'Prvi', 'theme' => 'light']],
            ['template' => 'kinds/cta-story', 'seconds' => 3.0, 'data' => []],
        ]]));

        $this->artisan('hub:render-storyboard', ['manifest' => $manifest, '--brand' => 'uselisto', '--output' => $this->directory.'/out2'])->assertSuccessful();

        $this->assertSame([], $this->requests);

        foreach (glob($this->directory.'/out2/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory.'/out2');
    }

    private function brand(): Brand
    {
        return Brand::factory()->create([
            'slug' => 'uselisto',
            'name' => 'Listo',
            'voice' => ['cta' => 'Preuzmi Listo', 'activation' => 'Dodaj prvi proizvod s letka.'],
            'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1'],
        ]);
    }
}
