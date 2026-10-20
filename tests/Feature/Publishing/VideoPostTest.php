<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Actions\CreateVideoPost;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\SourceType;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Publishing\FormatCheck;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A video made elsewhere is posted on the brand's channels with the words a person wrote for each, and it is held to
 * the rules every other post is.
 */
final class VideoPostTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('hub.media_disk', 'public');

        $this->brand = Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com']);

        foreach ([Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok] as $platform) {
            SocialAccount::factory()->create(['brand_id' => $this->brand->id, 'platform' => $platform, 'name' => 'Listo '.$platform->value]);
        }

        $this->directory = sys_get_temp_dir().'/hub-video-post-'.bin2hex(random_bytes(4));
        mkdir($this->directory);

        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=c=blue:s=216x384:d=2:r=30', '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo', '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $this->directory.'/video.mp4']);
        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=c=red:s=216x384', '-frames:v', '1', $this->directory.'/cover.png']);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_a_finished_video_becomes_one_draft_with_a_video_channel_for_each_network(): void
    {
        $draft = $this->makePost();

        $this->assertSame(DraftStatus::PendingApproval, $draft->status);
        $this->assertCount(3, $draft->variants);

        foreach ($draft->variants as $variant) {
            $this->assertSame(ContentFormat::Video, $variant->format());
            $this->assertNull(app(FormatCheck::class)->problem($variant), $variant->platform->value.' has what its format needs');
        }
    }

    public function test_every_channel_carries_its_own_words_its_own_first_comment_and_the_ai_label(): void
    {
        $draft = $this->makePost();

        $tiktok = $draft->variants->firstWhere('platform', Platform::TikTok);
        $facebook = $draft->variants->firstWhere('platform', Platform::FacebookPage);

        $this->assertSame('Tekst za TikTok', $tiktok->caption);
        $this->assertSame('👉 Preuzmi Listo: uselisto.com/tiktok', $tiktok->setting('first_comment'));
        $this->assertSame('https://uselisto.com/tiktok', $tiktok->link_url);
        $this->assertSame(800, $tiktok->setting('cover_timestamp_ms'));
        $this->assertSame('comment', $tiktok->setting('link_in'));

        $this->assertSame('Tekst za Facebook', $facebook->caption);
        $this->assertSame('https://uselisto.com/app', $facebook->link_url, 'a channel without a link of its own points at the item');

        foreach ($draft->variants as $variant) {
            $this->assertTrue($variant->aiGenerated(), $variant->platform->value.' is labelled as AI-generated');
        }
    }

    public function test_the_label_can_be_left_off_when_the_video_is_not_synthetic(): void
    {
        $draft = $this->makePost(aiGenerated: false);

        foreach ($draft->variants as $variant) {
            $this->assertFalse($variant->aiGenerated());
        }
    }

    public function test_the_video_is_stored_where_the_networks_fetch_it_and_the_cover_is_a_jpeg(): void
    {
        $draft = $this->makePost();

        $asset = $draft->variants->first()->media->first();

        $this->assertTrue($asset->isVideo());
        $this->assertStringStartsWith('media/uselisto/', $asset->path);
        $this->assertSame(216, $asset->width);
        $this->assertSame(384, $asset->height);
        $this->assertEqualsWithDelta(2000, $asset->duration_ms, 200);
        Storage::disk('public')->assertExists($asset->path);

        $poster = MediaAsset::query()->findOrFail($asset->poster_media_asset_id);
        $this->assertSame('jpg', $poster->format);
        $this->assertSame(1080, $poster->width);
        Storage::disk('public')->assertExists($poster->path);
    }

    public function test_a_plain_item_stands_behind_the_post_so_the_ordinary_guards_still_apply(): void
    {
        $until = CarbonImmutable::parse('2026-10-27 23:59', 'Europe/Zagreb')->utc();
        $draft = $this->makePost(validUntil: $until);

        $item = $draft->contentItems->first();

        $this->assertSame(ContentKind::Generic, $item->kind);
        $this->assertSame('https://uselisto.com/app', $item->url);
        $this->assertTrue($item->expires_at->equalTo($until));
        $this->assertSame(SourceType::Webhook, $item->source->type, 'a source nothing pulls and no rule reads');
        $this->assertSame(CreateVideoPost::SOURCE_NAME, $item->source->name);
        $this->assertTrue($draft->staleItems()->isEmpty(), 'a draft made just now is not "changed since"');

        $this->travelTo($until->addMinute());
        $this->assertTrue($item->fresh()->isExpired(), 'after the offer on the screen ran out the post is skipped');
    }

    public function test_approved_with_a_time_is_scheduled_for_that_time(): void
    {
        $at = CarbonImmutable::parse('2026-10-20 19:30', 'Europe/Zagreb')->utc();

        $draft = $this->makePost(status: DraftStatus::Approved, scheduledAt: $at);

        $this->assertSame(DraftStatus::Scheduled, $draft->status);
        $this->assertTrue($draft->scheduled_at->equalTo($at));
    }

    public function test_the_same_video_is_not_posted_twice_until_the_first_post_is_given_up(): void
    {
        $first = $this->makePost();

        try {
            $this->makePost();
            $this->fail('A second post of the same file should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("#{$first->id}", $e->getMessage());
        }

        $first->forceFill(['status' => DraftStatus::Discarded])->save();

        $second = $this->makePost();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $this->brand->contentItems()->count(), 'the same item is reused, not copied');
    }

    public function test_what_cannot_be_posted_is_refused_before_anything_is_stored(): void
    {
        foreach ([
            'unknown channel' => ['channels' => ['myspace' => ['caption' => 'x']], 'message' => 'Nepoznat kanal'],
            'no words' => ['channels' => ['tiktok' => ['caption' => ' ']], 'message' => 'nedostaje tekst'],
            'a group is posted by hand' => ['channels' => ['fb_group' => ['caption' => 'x']], 'message' => 'ne objavljuje video'],
            'no account' => ['channels' => ['youtube' => ['caption' => 'x']], 'message' => 'nema aktivan račun'],
            'no channel' => ['channels' => [], 'message' => 'barem jedan kanal'],
        ] as $case => $request) {
            try {
                $this->makePost(channels: $request['channels']);
                $this->fail("{$case} should have been refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($request['message'], $e->getMessage(), $case);
            }
        }

        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, PostVariant::query()->count());
    }

    public function test_a_file_that_is_not_a_video_is_refused(): void
    {
        file_put_contents($this->directory.'/broken.mp4', str_repeat('x', 2000));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ffprobe');

        app(CreateVideoPost::class)->execute($this->brand, $this->directory.'/broken.mp4', 'Naslov', $this->channels(), 'https://uselisto.com/app');
    }

    public function test_the_command_reads_a_manifest_and_makes_the_draft(): void
    {
        file_put_contents($this->directory.'/manifest.json', json_encode([
            'title' => 'Pilot',
            'url' => 'https://uselisto.com/app',
            'video' => 'video.mp4',
            'cover' => 'cover.png',
            'valid_until' => '2026-10-27 23:59',
            'ai_generated' => true,
            'channels' => $this->channels(),
        ], JSON_THROW_ON_ERROR));

        $this->artisan('hub:video-post', ['manifest' => $this->directory.'/manifest.json', '--at' => '2026-10-20 19:30', '--approve' => true])
            ->expectsOutputToContain('Objava #')
            ->assertSuccessful();

        $draft = $this->brand->postDrafts()->firstOrFail();

        $this->assertSame(DraftStatus::Scheduled, $draft->status);
        $this->assertSame('2026-10-20 17:30:00', $draft->scheduled_at->format('Y-m-d H:i:s'));
        // The clocks go back on 25 October: 23:59 on the 27th is already winter time.
        $this->assertSame('2026-10-27 22:59:00', $draft->contentItems->first()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_the_command_says_why_it_cannot_post(): void
    {
        file_put_contents($this->directory.'/manifest.json', json_encode(['video' => 'missing.mp4', 'channels' => $this->channels()], JSON_THROW_ON_ERROR));

        $this->artisan('hub:video-post', ['manifest' => $this->directory.'/manifest.json'])->expectsOutputToContain('Objava treba naslov')->assertFailed();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function channels(): array
    {
        return [
            'tiktok' => ['caption' => 'Tekst za TikTok', 'first_comment' => '👉 Preuzmi Listo: uselisto.com/tiktok', 'link_url' => 'https://uselisto.com/tiktok', 'cover_timestamp_ms' => 800],
            'ig_business' => ['caption' => 'Tekst za Instagram', 'first_comment' => '👉 Preuzmi Listo: uselisto.com/instagram', 'link_url' => 'https://uselisto.com/instagram'],
            'fb_page' => ['caption' => 'Tekst za Facebook', 'first_comment' => '👉 Preuzmi Listo: https://uselisto.com/app?utm_source=facebook'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $channels
     */
    private function makePost(?array $channels = null, bool $aiGenerated = true, ?CarbonImmutable $validUntil = null, DraftStatus $status = DraftStatus::PendingApproval, ?CarbonImmutable $scheduledAt = null): \App\Models\PostDraft
    {
        return app(CreateVideoPost::class)->execute(
            brand: $this->brand,
            videoPath: $this->directory.'/video.mp4',
            title: 'Pilot',
            channels: $channels ?? $this->channels(),
            url: 'https://uselisto.com/app',
            coverPath: $this->directory.'/cover.png',
            validUntil: $validUntil,
            aiGenerated: $aiGenerated,
            scheduledAt: $scheduledAt,
            status: $status,
        );
    }

    /**
     * @param  list<string>  $arguments
     */
    private function ffmpeg(array $arguments): void
    {
        $process = new Process(['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error', ...$arguments]);
        $process->setTimeout(60)->run();

        if (! $process->isSuccessful()) {
            $this->markTestSkipped('ffmpeg is needed to make a test video: '.$process->getErrorOutput());
        }
    }
}
