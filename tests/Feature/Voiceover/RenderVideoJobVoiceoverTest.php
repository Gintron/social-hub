<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Actions\CreateDraft;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Jobs\RenderVideoJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Notifications\VoiceoverUnavailable;
use App\Publishing\FormatCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeSpeech;
use Tests\Support\MakesVideoFixtures;
use Tests\TestCase;

/**
 * The whole path with the real renderers — Chromium for the slides, ffmpeg for the video — and only
 * ElevenLabs faked: a narrated video comes out, and when the voice cannot be made the video comes out
 * anyway.
 */
#[Group('render')]
final class RenderVideoJobVoiceoverTest extends TestCase
{
    use MakesVideoFixtures;
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $requests = [];

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
        config()->set('hub.admin_emails', ['ops@example.test']);
    }

    public function test_a_narrated_video_comes_out_with_the_voice_in_it_and_every_line_kept(): void
    {
        FakeSpeech::fake($this->requests);
        Http::fake(['*' => Http::response('', 404)]); // source images are unreachable: the cards use their placeholder

        [$draft, $variant] = $this->draft();

        RenderVideoJob::dispatchSync($draft->id, [$variant->id], voiceover: true);

        $asset = $variant->refresh()->media()->firstOrFail();
        $this->assertTrue($asset->isVideo());
        $this->assertSame('ok', $asset->params['voiceover']['status']);
        $this->assertSame('voice-1', $asset->params['voiceover']['voice_id']);
        $this->assertCount(3, $asset->params['voiceover']['script']);
        $this->assertSame('Kruh bijeli 500 g u trgovini Konzum za 1,49 €.', $asset->params['voiceover']['script'][0]['text']);
        $this->assertCount(3, $this->requests);

        // The picture waits for the words: the hook alone needs its whole line, so the video is longer than the
        // 8.8 seconds the same slides take in silence.
        $this->assertGreaterThan(9.5, (float) $asset->durationSeconds());
        $this->assertEqualsWithDelta((float) $asset->durationSeconds(), $this->streamSeconds($asset->absolutePath(), 'audio'), 0.2);
        $this->assertGreaterThan(-35.0, $this->level($asset->absolutePath(), 0.4, 3.0), 'the first line is heard');
        $this->assertNull(app(FormatCheck::class)->problem($variant), 'it is ready to publish');
    }

    public function test_when_the_voice_cannot_be_made_the_video_still_comes_out_and_says_why(): void
    {
        Notification::fake();
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'quota_exceeded', 'message' => 'You have 0 credits']], 401),
            '*' => Http::response('', 404),
        ]);

        [$draft, $variant] = $this->draft();

        RenderVideoJob::dispatchSync($draft->id, [$variant->id], voiceover: true);

        $asset = $variant->refresh()->media()->firstOrFail();
        $this->assertTrue($asset->isVideo(), 'a post never waits for its voice');
        $this->assertSame('failed', $asset->params['voiceover']['status']);
        $this->assertSame('quota_exceeded', $asset->params['voiceover']['code']);
        $this->assertStringContainsString('quota', $asset->params['voiceover']['error']);
        $this->assertNull($variant->renderError());
        $this->assertNull(app(FormatCheck::class)->problem($variant), 'the silent video is publishable');
        $this->assertEqualsWithDelta(8.8, (float) $asset->durationSeconds(), 0.1, 'the slides keep the time they always had');

        Notification::assertSentOnDemand(VoiceoverUnavailable::class, fn (VoiceoverUnavailable $notification): bool => $notification->errorCode === 'quota_exceeded');
    }

    public function test_an_admin_is_told_once_not_once_per_video(): void
    {
        Notification::fake();
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key']], 401),
            '*' => Http::response('', 404),
        ]);

        [$first, $firstVariant] = $this->draft();
        RenderVideoJob::dispatchSync($first->id, [$firstVariant->id], voiceover: true);

        [$second, $secondVariant] = $this->draft($first->brand);
        RenderVideoJob::dispatchSync($second->id, [$secondVariant->id], voiceover: true);

        Notification::assertSentOnDemandTimes(VoiceoverUnavailable::class, 1);
        $this->assertSame('failed', $secondVariant->refresh()->media()->firstOrFail()->params['voiceover']['status']);
    }

    public function test_a_plain_render_never_touches_the_voice(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        [$draft, $variant] = $this->draft();

        RenderVideoJob::dispatchSync($draft->id, [$variant->id]);

        $asset = $variant->refresh()->media()->firstOrFail();
        $this->assertNull($asset->params['voiceover']);
        $this->assertEqualsWithDelta(8.8, (float) $asset->durationSeconds(), 0.1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'elevenlabs'));
    }

    public function test_a_script_somebody_wrote_is_what_is_said(): void
    {
        FakeSpeech::fake($this->requests);
        Http::fake(['*' => Http::response('', 404)]);

        [$draft, $variant] = $this->draft();

        RenderVideoJob::dispatchSync($draft->id, [$variant->id], voiceover: true, script: ['Pogledaj ovu akciju.', '', 'Preuzmi Listo.']);

        $asset = $variant->refresh()->media()->firstOrFail();
        $this->assertSame(['Pogledaj ovu akciju.', 'Preuzmi Listo.'], array_column($this->requests, 'text'));
        $this->assertSame(['Pogledaj ovu akciju.', '', 'Preuzmi Listo.'], array_column($asset->params['voiceover']['script'], 'text'));
    }

    /**
     * @return array{0: PostDraft, 1: PostVariant}
     */
    private function draft(?Brand $brand = null): array
    {
        $brand ??= Brand::factory()->create([
            'slug' => 'uselisto',
            'name' => 'Listo',
            'site_url' => 'https://uselisto.com',
            'voice' => ['cta' => 'Preuzmi Listo', 'activation' => 'Dodaj prvi proizvod s letka.'],
            'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1'],
        ]);

        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Deal,
            'title' => 'Kruh bijeli 500 g',
            'subtitle' => 'Konzum',
            'price' => ['current_cents' => 149, 'old_cents' => 199, 'discount_pct' => 25, 'currency' => 'EUR', 'unit_label' => null],
            'facts' => [],
            'badges' => ['−25 %'],
            'images' => [],
            'expires_at' => '2026-12-31T23:59:59Z',
        ]);

        $draft = app(CreateDraft::class)->execute(
            $item,
            [SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok])],
            status: DraftStatus::PendingApproval,
            render: false,
        );

        /** @var PostVariant $variant */
        $variant = $draft->variants->first();

        return [$draft, $variant];
    }
}
