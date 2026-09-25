<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Actions\ApproveDraft;
use App\Actions\ChangeVariantFormat;
use App\Actions\CreateDraft;
use App\Actions\DispatchDraftPublishing;
use App\Actions\PrepareVariantMedia;
use App\Actions\UpdateVariant;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Jobs\PublishVariantJob;
use App\Jobs\RenderDigestJob;
use App\Jobs\RenderMediaJob;
use App\Jobs\RenderSlidesJob;
use App\Jobs\RenderVideoJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Publishing\FormatCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;
use Throwable;

/**
 * Every channel posts its own format with its own media. The failure this guards against is the
 * old one: rendering a video for TikTok silently replaced the image Facebook was about to post.
 */
final class VariantFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('hub.media_disk', 'public');
    }

    public function test_each_channel_gets_media_for_its_own_default_format(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [
            SocialAccount::factory()->for($brand)->create(),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::InstagramBusiness]),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]),
        ]);

        $page = $this->variantFor($draft, Platform::FacebookPage);
        $instagram = $this->variantFor($draft, Platform::InstagramBusiness);
        $tiktok = $this->variantFor($draft, Platform::TikTok);

        $this->assertSame(ContentFormat::Image, $page->format());
        $this->assertSame(ContentFormat::Video, $tiktok->format());

        // Facebook and Instagram share one 4:5 render; TikTok gets a video of its own.
        Queue::assertPushed(RenderMediaJob::class, 1);
        Queue::assertPushed(RenderMediaJob::class, fn (RenderMediaJob $job): bool => $job->templateKey === 'kinds/job-portrait'
            && $job->variantIds === [$page->id, $instagram->id]);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$tiktok->id]);

        $this->assertTrue($tiktok->refresh()->isRendering());
        $this->assertSame('Video se još renderira.', app(FormatCheck::class)->problem($tiktok));
    }

    public function test_one_item_as_a_carousel_is_its_slide_set_and_on_tiktok_a_video_of_them(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::InstagramBusiness]),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]),
        ], channelOptions: [
            'ig_business' => ['format' => ContentFormat::Carousel],
            'tiktok' => ['settings' => ['delivery' => 'inbox']],
        ]);

        $instagram = $this->variantFor($draft, Platform::InstagramBusiness);
        $tiktok = $this->variantFor($draft, Platform::TikTok);

        $this->assertSame('inbox', $tiktok->setting('delivery'));
        $this->assertSame(ContentFormat::Video, $tiktok->format(), 'TikTok je uvijek video');
        Queue::assertPushed(RenderSlidesJob::class, 1);
        Queue::assertPushed(RenderSlidesJob::class, fn (RenderSlidesJob $job): bool => $job->variantIds === [$instagram->id]
            && $job->templateKeys === ['kinds/hook-portrait', 'kinds/job-portrait', 'kinds/cta-portrait']);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$tiktok->id]);
        Queue::assertNotPushed(RenderMediaJob::class);
    }

    public function test_a_format_the_platform_cannot_post_is_refused_when_the_draft_is_created(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ne podržava');

        app(CreateDraft::class)->execute(
            $item,
            [SocialAccount::factory()->for($brand)->create(['platform' => Platform::InstagramBusiness])],
            channelOptions: ['ig_business' => ['format' => ContentFormat::Link]],
        );
    }

    public function test_changing_one_channel_to_video_leaves_the_others_alone(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [
            SocialAccount::factory()->for($brand)->create(),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::InstagramBusiness]),
        ], render: false);

        $image = $this->image($brand, $draft, 'kinds/job-square');
        $draft->variants->each(fn (PostVariant $variant) => $variant->media()->attach($image->id, ['position' => 0]));

        $page = $this->variantFor($draft, Platform::FacebookPage);
        $instagram = $this->variantFor($draft, Platform::InstagramBusiness);

        app(ChangeVariantFormat::class)->execute($instagram, ContentFormat::Video);

        $this->assertSame(ContentFormat::Video, $instagram->refresh()->format());
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$instagram->id]);
        $this->assertSame([$image->id], $page->media()->pluck('media_assets.id')->all(), 'Facebook zadržava svoju sliku');
        $this->assertNull(app(FormatCheck::class)->problem($page));
    }

    public function test_a_format_the_draft_already_has_media_for_is_reused_without_rendering(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create()], render: false);
        $video = MediaAsset::factory()->for($brand)->create([
            'post_draft_id' => $draft->id,
            'template_key' => 'video/slideshow',
            'format' => 'mp4',
            'width' => 1080,
            'height' => 1920,
            'duration_ms' => 7800,
        ]);

        $page = app(ChangeVariantFormat::class)->execute($draft->variants->first(), ContentFormat::Video);

        Queue::assertNothingPushed();
        $this->assertSame([$video->id], $page->media()->pluck('media_assets.id')->all());
        $this->assertFalse($page->isRendering());
        $this->assertNull(app(FormatCheck::class)->problem($page));
    }

    public function test_link_drops_the_media_and_needs_none(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create()], render: false);
        $variant = $draft->variants->first();
        $variant->media()->attach($this->image($brand, $draft, 'kinds/job-square')->id, ['position' => 0]);
        $variant->forceFill(['settings' => ['mode' => 'photo']])->save();

        $variant = app(ChangeVariantFormat::class)->execute($variant, ContentFormat::Link);

        $this->assertSame(ContentFormat::Link, $variant->format());
        $this->assertNull($variant->setting('mode'), 'stari Facebook ključ ne smije nadjačati novi format');
        $this->assertSame(0, $variant->media()->count());
        $this->assertNull(app(FormatCheck::class)->problem($variant));
    }

    public function test_a_channel_cannot_take_a_format_its_platform_lacks_or_change_after_publishing(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [
            SocialAccount::factory()->for($brand)->create(),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::InstagramBusiness]),
        ], render: false);

        try {
            app(ChangeVariantFormat::class)->execute($this->variantFor($draft, Platform::InstagramBusiness), ContentFormat::Link);
            $this->fail('Instagram nema link objave.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ne podržava', $e->getMessage());
        }

        $page = $this->variantFor($draft, Platform::FacebookPage);
        $page->forceFill(['status' => VariantStatus::Published, 'external_post_id' => '9_1'])->save();

        $this->expectException(InvalidArgumentException::class);

        app(ChangeVariantFormat::class)->execute($page, ContentFormat::Video);
    }

    public function test_settings_from_before_formats_still_read_as_the_right_format(): void
    {
        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [
            SocialAccount::factory()->for($brand)->create(),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::InstagramBusiness]),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]),
        ], render: false);

        $page = $this->variantFor($draft, Platform::FacebookPage);
        $instagram = $this->variantFor($draft, Platform::InstagramBusiness);
        $tiktok = $this->variantFor($draft, Platform::TikTok);

        $instagram->forceFill(['settings' => ['format' => 'reel']])->save();
        $this->assertSame(ContentFormat::Video, $instagram->format());

        $instagram->forceFill(['settings' => ['format' => 'post']])->save();
        $this->assertSame(ContentFormat::Image, $instagram->fresh()->format());

        $page->forceFill(['settings' => ['mode' => 'link']])->save();
        $this->assertSame(ContentFormat::Link, $page->format());

        $page->forceFill(['settings' => ['mode' => 'photo']])->save();
        $page->media()->attach($this->image($brand, $draft, 'kinds/job-square')->id, ['position' => 0]);
        $page->media()->attach($this->image($brand, $draft, 'kinds/job-square')->id, ['position' => 1]);
        $this->assertSame(ContentFormat::Carousel, $page->fresh()->format(), 'više slika bio je carousel');

        $tiktok->forceFill(['settings' => null])->save();
        $this->assertSame(ContentFormat::Video, $tiktok->format());
    }

    public function test_publishing_waits_for_a_render_and_refuses_media_that_does_not_fit(): void
    {
        Http::fake(['example.test/*' => Http::response('', 200), 'graph.facebook.com/*' => Http::response(['id' => '1'])]);

        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create()], render: false);
        $variant = $draft->variants->first();
        $variant->forceFill(['settings' => ['format' => 'video']])->save();
        $variant->markRendering();

        app(ApproveDraft::class)->execute($draft, null);
        app(DispatchDraftPublishing::class)->execute($draft->refresh());

        // Still rendering: back in the queue, not failed, and nothing sent to Meta.
        $variant->refresh();
        $this->assertSame(VariantStatus::Queued, $variant->status);
        $this->assertSame('media_rendering', $variant->error_code);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'graph.facebook.com'));

        // Render finished without a video (it failed on the worker): now it is a real failure.
        $variant->markRendered();
        PublishVariantJob::dispatchSync($variant->id);

        $variant->refresh();
        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('media_not_ready', $variant->error_code);
        $this->assertStringContainsString('Video još nije renderiran', (string) $variant->error_message);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_a_failed_render_is_reported_on_the_channel_instead_of_spinning_forever(): void
    {
        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create()], render: false);
        $variant = $draft->variants->first();
        $variant->markRendering();

        try {
            RenderMediaJob::dispatchSync($draft->id, $item->id, 'kinds/does-not-exist', [$variant->id]);
            $this->fail('Nepostojeći predložak mora srušiti render.');
        } catch (Throwable) {
            // expected
        }

        $variant->refresh();
        $this->assertFalse($variant->isRendering());
        $this->assertStringContainsString('does-not-exist', (string) $variant->renderError());
        $this->assertStringStartsWith('Render nije uspio', (string) app(FormatCheck::class)->problem($variant));
    }

    public function test_a_digest_carousel_is_rendered_once_for_facebook_and_instagram_and_tiktok_gets_a_video(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [
            SocialAccount::factory()->for($brand)->create(),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::InstagramBusiness]),
            SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]),
        ], render: false);
        $draft->forceFill(['kind' => PostDraft::KIND_DIGEST, 'title' => '5 novih poslova'])->save();
        $draft->variants()->get()->each(fn (PostVariant $variant) => $variant->forceFill(['settings' => ['format' => 'carousel']])->save());

        app(PrepareVariantMedia::class)->execute($draft->variants()->get());

        $page = $this->variantFor($draft, Platform::FacebookPage);
        $instagram = $this->variantFor($draft, Platform::InstagramBusiness);
        $tiktok = $this->variantFor($draft, Platform::TikTok);

        Queue::assertPushed(RenderDigestJob::class, 1);
        Queue::assertPushed(RenderDigestJob::class, fn (RenderDigestJob $job): bool => $job->coverTemplate === 'kinds/digest-cover-portrait'
            && $job->variantIds === [$page->id, $instagram->id] && ! $job->coverOnly);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$tiktok->id]);
    }

    public function test_a_render_finishing_late_cannot_undo_a_format_change(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create()], render: false);
        $id = $draft->variants->first()->id;

        // The copy a render job loaded before it started encoding.
        $jobCopy = PostVariant::query()->findOrFail($id);
        $jobCopy->markRendering();

        // Meanwhile the user switches the channel on the review screen.
        app(ChangeVariantFormat::class)->execute(PostVariant::query()->findOrFail($id), ContentFormat::Link);

        $jobCopy->markRendered();

        $variant = PostVariant::query()->findOrFail($id);
        $this->assertSame(ContentFormat::Link, $variant->format());
        $this->assertFalse($variant->isRendering());
    }

    public function test_saving_the_form_does_not_bring_back_a_finished_render(): void
    {
        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok])], render: false);
        $id = $draft->variants->first()->id;

        PostVariant::query()->findOrFail($id)->markRendering();
        $formCopy = PostVariant::query()->findOrFail($id);
        PostVariant::query()->findOrFail($id)->markRendered();

        app(UpdateVariant::class)->execute($formCopy, caption: 'Novi tekst', settings: ['delivery' => 'inbox']);

        $variant = PostVariant::query()->findOrFail($id);
        $this->assertFalse($variant->isRendering(), 'spremanje obrasca ne smije vratiti oznaku rendera');
        $this->assertSame('inbox', $variant->setting('delivery'));
        $this->assertSame(ContentFormat::Video, $variant->format());
        $this->assertSame('Novi tekst', $variant->caption);
    }

    public function test_a_render_for_a_format_the_channel_left_is_not_attached(): void
    {
        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create()], render: false);
        $variant = $draft->variants->first();

        // An image render was queued, then the channel switched to a video it already had.
        $video = MediaAsset::factory()->for($brand)->create([
            'post_draft_id' => $draft->id, 'template_key' => 'video/slideshow', 'format' => 'mp4',
            'width' => 1080, 'height' => 1920, 'duration_ms' => 7800,
        ]);
        $variant->putSettings(['format' => 'video']);
        $variant->media()->sync([$video->id => ['position' => 0]]);
        $variant->markRendering();

        try {
            RenderMediaJob::dispatchSync($draft->id, $item->id, 'kinds/does-not-exist', [$variant->id]);
        } catch (Throwable) {
            // the late image render fails; that failure belongs to a format the channel left
        }

        $variant = $variant->fresh();
        $this->assertNull($variant->renderError(), 'greška starog rendera ne ide na kanal koji je promijenio format');
        $this->assertSame([$video->id], $variant->media()->pluck('media_assets.id')->all());
    }

    public function test_a_digest_job_queued_before_this_change_still_has_its_defaults(): void
    {
        // What unserializing an older payload produces: no constructor, only declared defaults.
        $job = (new ReflectionClass(RenderDigestJob::class))->newInstanceWithoutConstructor();

        $this->assertNull($job->variantIds);
        $this->assertFalse($job->coverOnly);
    }

    /**
     * @return array{0: Brand, 1: ContentItem}
     */
    private function brandWithItem(): array
    {
        $brand = Brand::factory()->create(['slug' => 'studentski-poslovi']);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['title' => 'Konobar/ica']);

        return [$brand, $item];
    }

    private function variantFor(PostDraft $draft, Platform $platform): PostVariant
    {
        return $draft->variants()->where('platform', $platform->value)->firstOrFail();
    }

    private function image(Brand $brand, PostDraft $draft, string $templateKey): MediaAsset
    {
        return MediaAsset::factory()->for($brand)->create([
            'post_draft_id' => $draft->id,
            'template_key' => $templateKey,
            'format' => 'jpg',
            'width' => 1080,
            'height' => 1080,
        ]);
    }
}
