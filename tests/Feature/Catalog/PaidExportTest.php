<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\CreateDraft;
use App\Catalog\PaidExport;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\MakesCatalogItems;
use Tests\TestCase;

/**
 * Phase 4: the video as posted, its cover, the ad's own tracked link and the text, in one folder to paste from.
 */
final class PaidExportTest extends TestCase
{
    use MakesCatalogItems;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('public');
        config()->set('elevenlabs.api_key', null);
        $this->dir = sys_get_temp_dir().'/catalog-export-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_it_collects_video_cover_link_and_text_for_the_promotion_tool(): void
    {
        $draft = $this->draftWithVideo();

        $result = app(PaidExport::class)->export($draft, Platform::TikTok, $this->dir);

        $this->assertSame('https://uselisto.com/app?utm_source=tiktok&utm_medium=paid&utm_campaign=katalog-konzum-2026-10-07', $result['url']);
        $this->assertSame(['video.mp4', 'naslovnica.jpg', 'poveznica.txt', 'tekst.txt', 'export.json'], $result['files']);
        $this->assertSame('video-bytes', file_get_contents($this->dir.'/video.mp4'));
        $this->assertStringContainsString('Izašao je novi Konzumov katalog', (string) file_get_contents($this->dir.'/tekst.txt'));
        $this->assertSame($result['url']."\n", file_get_contents($this->dir.'/poveznica.txt'));

        $meta = json_decode((string) file_get_contents($this->dir.'/export.json'), true);
        $this->assertSame('paid', $meta['utm']['medium']);
        $this->assertSame('katalog-konzum-2026-10-07', $meta['utm']['campaign']);
        $this->assertSame('tiktok', $meta['platform']);
        $this->assertSame(['2026-10-07', '2026-10-13'], $meta['valid']);
    }

    public function test_a_channel_without_a_finished_video_is_refused_not_exported_empty(): void
    {
        $draft = $this->draftWithVideo(attach: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nema gotov video');

        app(PaidExport::class)->export($draft, Platform::TikTok, $this->dir);
    }

    public function test_a_draft_that_is_not_a_catalog_is_refused(): void
    {
        $brand = Brand::factory()->create();
        $item = ContentItem::factory()->for(\App\Models\Source::factory()->for($brand))->for($brand)->create();
        $draft = app(CreateDraft::class)->execute($item, [\App\Models\SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok])], render: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nije video kataloga');

        app(PaidExport::class)->export($draft, Platform::TikTok, $this->dir);
    }

    public function test_the_command_says_where_the_folder_is_and_what_the_ads_link_is(): void
    {
        $draft = $this->draftWithVideo();

        $this->artisan('hub:catalog-export', ['draft' => $draft->id, '--out' => $this->dir])
            ->expectsOutputToContain('utm_medium=paid')
            ->assertSuccessful();
    }

    private function draftWithVideo(bool $attach = true): PostDraft
    {
        $brand = Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo', 'voice' => ['cta' => 'Preuzmi Listo'], 'site_url' => 'https://uselisto.com']);
        $draft = app(CreateDraft::class)->execute($this->catalogItem($brand), $this->catalogAccounts($brand), render: false);

        if ($attach) {
            Storage::disk('public')->put('media/catalog.mp4', 'video-bytes');
            Storage::disk('public')->put('media/catalog.jpg', 'cover-bytes');
            $poster = MediaAsset::factory()->for($brand)->create(['format' => 'jpg', 'path' => 'media/catalog.jpg']);
            $video = MediaAsset::factory()->for($brand)->create(['format' => 'mp4', 'path' => 'media/catalog.mp4', 'duration_ms' => 16_000, 'poster_media_asset_id' => $poster->id, 'params' => ['voiceover' => ['status' => 'ok']]]);
            $draft->variants->firstWhere('platform', Platform::TikTok)->media()->attach($video->id, ['position' => 0]);
        }

        return $draft->fresh(['variants']);
    }
}
