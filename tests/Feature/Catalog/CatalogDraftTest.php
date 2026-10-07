<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\CreateDraft;
use App\Actions\PrepareVariantMedia;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Jobs\RenderCatalogVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MakesCatalogItems;
use Tests\TestCase;

/**
 * A leaflet becomes one draft with a channel for each network — video where the network takes one — and
 * every channel carries its own link, so what an install came from is known.
 */
final class CatalogDraftTest extends TestCase
{
    use MakesCatalogItems;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('elevenlabs.api_key', null);
    }

    public function test_the_three_channels_post_the_video_and_a_group_a_link(): void
    {
        $draft = $this->draft([Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok, Platform::FacebookGroup]);

        $formats = $draft->variants->mapWithKeys(fn (PostVariant $variant): array => [$variant->platform->value => $variant->format()])->all();

        $this->assertSame(ContentFormat::Video, $formats['fb_page']);
        $this->assertSame(ContentFormat::Video, $formats['ig_business']);
        $this->assertSame(ContentFormat::Video, $formats['tiktok']);
        $this->assertSame(ContentFormat::Link, $formats['fb_group']);
    }

    public function test_a_channel_whose_link_can_be_tapped_gets_its_own_tracked_link_kept_for_attribution(): void
    {
        $draft = $this->draft();
        $variant = $draft->variants->firstWhere('platform', Platform::FacebookPage);
        $url = 'https://uselisto.com/app?utm_source=facebook&utm_medium=social&utm_campaign=katalog-konzum-2026-10-07';

        $this->assertSame($url, $variant->link_url);
        // MySQL keeps JSON keys in its own order; what counts is what is stored, not where.
        $this->assertEquals(['url' => $url, 'source' => 'facebook', 'medium' => 'social', 'campaign' => 'katalog-konzum-2026-10-07'], $variant->setting('tracking'));
    }

    public function test_tiktok_and_instagram_say_the_bare_address_because_that_is_what_people_copy(): void
    {
        $draft = $this->draft();

        foreach ([Platform::InstagramBusiness, Platform::TikTok] as $platform) {
            $variant = $draft->variants->firstWhere('platform', $platform);

            $this->assertSame('👉 Preuzmi Listo: uselisto.com/app', $variant->setting('first_comment'), $platform->value);
            $this->assertSame('https://uselisto.com/app', $variant->link_url, $platform->value);
            $this->assertNull($variant->setting('tracking'), 'nothing is stored as tracked when the comment does not carry the tracking');
        }
    }

    public function test_the_bare_address_is_a_choice_of_the_config_and_the_tracked_link_comes_back_with_it(): void
    {
        config()->set('catalog_video.link_typed.tiktok', false);

        $tiktok = $this->draft()->variants->firstWhere('platform', Platform::TikTok);

        $this->assertStringContainsString('utm_source=tiktok', (string) $tiktok->setting('first_comment'));
        $this->assertStringContainsString('utm_source=tiktok', (string) $tiktok->setting('tracking.url'));
    }

    public function test_every_channel_that_says_the_link_is_in_the_comment_has_one_to_leave(): void
    {
        $draft = $this->draft();

        $this->assertSame(
            '👉 Preuzmi Listo: https://uselisto.com/app?utm_source=facebook&utm_medium=social&utm_campaign=katalog-konzum-2026-10-07',
            $draft->variants->firstWhere('platform', Platform::FacebookPage)->setting('first_comment'),
        );
        $this->assertNotNull($draft->variants->firstWhere('platform', Platform::InstagramBusiness)->setting('first_comment'));
        // TikTok's is left by its own job once the post is public (LeaveTikTokCommentJob), or pasted by hand.
        $this->assertNotNull($draft->variants->firstWhere('platform', Platform::TikTok)->setting('first_comment'));
    }

    public function test_the_text_of_each_channel_says_where_the_link_is_and_none_carries_it(): void
    {
        $draft = $this->draft();

        foreach ($draft->variants as $variant) {
            $this->assertStringContainsString('Izašao je novi Konzumov katalog', $variant->caption);
            $this->assertStringContainsString('Poveznica do aplikacije je u komentaru', $variant->caption);
            $this->assertStringNotContainsString('http', $variant->caption);
        }
    }

    public function test_a_group_post_keeps_the_link_in_the_text_because_nobody_comments_there(): void
    {
        $draft = $this->draft([Platform::FacebookGroup]);
        $variant = $draft->variants->first();

        $this->assertStringContainsString('https://uselisto.com/app', $variant->caption);
        $this->assertNull($variant->setting('first_comment'));
        $this->assertNull($variant->setting('tracking'));
    }

    public function test_the_video_is_rendered_by_its_own_job_once_for_channels_that_say_the_same(): void
    {
        $this->draft(render: true);

        Queue::assertNotPushed(RenderVideoJob::class);
        Queue::assertPushed(RenderCatalogVideoJob::class, 1);
        Queue::assertPushed(RenderCatalogVideoJob::class, fn (RenderCatalogVideoJob $job): bool => count($job->variantIds) === 3 && $job->where === 'comment' && ! $job->voiceover);
    }

    public function test_a_channel_that_says_the_link_is_in_the_bio_needs_a_video_of_its_own(): void
    {
        config()->set('catalog_video.link_in.tiktok', 'bio');

        $draft = $this->draft(render: true);
        $tiktok = $draft->variants->firstWhere('platform', Platform::TikTok);

        Queue::assertPushed(RenderCatalogVideoJob::class, 2);
        Queue::assertPushed(RenderCatalogVideoJob::class, fn (RenderCatalogVideoJob $job): bool => $job->variantIds === [$tiktok->id] && $job->where === 'bio');
        $this->assertSame('bio', $tiktok->setting('link_in'));
        $this->assertSame('Poveznica do aplikacije je u biografiji.', $this->lastLine($tiktok));
        $this->assertNull($tiktok->setting('first_comment'), 'a link in the bio is not a comment');
    }

    public function test_a_video_that_already_exists_for_the_same_voice_and_link_is_reused_and_another_is_not(): void
    {
        $draft = $this->draft();
        $item = $draft->contentItems->first();
        $video = MediaAsset::factory()->for($draft->brand)->create([
            'post_draft_id' => $draft->id,
            'template_key' => 'video/catalog-demo',
            'format' => 'mp4',
            'params' => ['link_in' => 'comment', 'voiceover' => null],
        ]);

        app(PrepareVariantMedia::class)->execute($draft->variants()->get());

        Queue::assertNotPushed(RenderCatalogVideoJob::class);
        $this->assertCount(3, $video->variants()->get(), 'every channel found the video it asked for');
        $this->assertNotNull($item);

        // A channel that wants the other sentence about the link does not take this one.
        $draft->variants->firstWhere('platform', Platform::TikTok)->putSettings(['link_in' => 'bio']);
        app(PrepareVariantMedia::class)->execute($draft->variants()->get());

        Queue::assertPushed(RenderCatalogVideoJob::class, 1);
    }

    public function test_a_catalog_without_a_usable_demo_still_gets_a_draft_with_the_plain_caption(): void
    {
        $feed = $this->catalogFeedItem();
        unset($feed['raw']['demo']);

        $brand = Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo']);
        $item = $this->catalogItem($brand, $feed);
        $draft = app(CreateDraft::class)->execute($item, $this->catalogAccounts($brand), render: false);

        $this->assertCount(3, $draft->variants);
        $this->assertSame($item->url, $draft->variants->first()->link_url, 'no tracked link without the block that names the campaign');
        $this->assertNull($draft->variants->first()->setting('tracking'));
    }

    /**
     * @param  list<Platform>  $platforms
     */
    private function draft(array $platforms = [Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok], bool $render = false): \App\Models\PostDraft
    {
        $brand = Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com', 'voice' => ['cta' => 'Preuzmi Listo', 'hashtags' => ['listo']]]);
        $item = $this->catalogItem($brand);

        return app(CreateDraft::class)->execute($item, $this->catalogAccounts($brand, $platforms), render: $render);
    }

    private function lastLine(PostVariant $variant): string
    {
        return \App\Catalog\CatalogCopy::linkSentence($variant->linkIn());
    }
}
