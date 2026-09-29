<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Actions\ChangeVariantVoiceover;
use App\Actions\CreateDraft;
use App\Actions\PrepareVariantMedia;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Jobs\RenderVideoJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

/**
 * Who gets a voice: the brand's switch, a channel's own, and which video a channel is given.
 */
final class VariantVoiceoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('elevenlabs.api_key', 'test-key');
    }

    /**
     * @return array<string, array{bool, string|null, array<string, mixed>, bool}>
     */
    public static function choices(): array
    {
        return [
            'a brand that has not turned it on' => [false, null, [], false],
            'a brand that has' => [true, null, [], true],
            'a channel switched off' => [true, 'off', [], false],
            'a channel switched on for a brand that has not' => [false, 'on', [], true],
            'switched on for a brand with no voice chosen' => [true, 'on', ['voice_id' => null], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $voiceover
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('choices')]
    public function test_a_video_is_narrated_when_the_channel_or_else_the_brand_says_so(bool $brandOn, ?string $channel, array $voiceover, bool $expected): void
    {
        $brand = $this->brand(['enabled' => $brandOn, ...$voiceover]);
        $variant = $this->draft($brand, [Platform::TikTok], render: false)->variants->first();
        $variant->putSettings(['voiceover' => $channel]);

        $this->assertSame($expected, $variant->refresh()->wantsVoiceover());
    }

    public function test_an_image_is_never_narrated_and_a_hub_without_a_key_never_narrates(): void
    {
        $brand = $this->brand(['enabled' => true]);
        $draft = $this->draft($brand, [Platform::FacebookPage, Platform::TikTok], render: false);
        $image = $draft->variants->firstWhere('platform', Platform::FacebookPage);
        $video = $draft->variants->firstWhere('platform', Platform::TikTok);

        $this->assertFalse($image->wantsVoiceover());
        $this->assertTrue($video->wantsVoiceover());

        config()->set('elevenlabs.api_key', null);
        $this->assertFalse($video->refresh()->wantsVoiceover());
    }

    public function test_a_brand_with_the_voice_on_renders_its_videos_narrated_without_anyone_asking(): void
    {
        $brand = $this->brand(['enabled' => true]);

        $draft = $this->draft($brand, [Platform::FacebookPage, Platform::TikTok]);
        $tiktok = $draft->variants->firstWhere('platform', Platform::TikTok);

        Queue::assertPushed(RenderVideoJob::class, 1);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$tiktok->id] && $job->voiceover === true && $job->script === null);
    }

    public function test_a_brand_that_has_not_asked_for_a_voice_renders_the_video_it_always_did(): void
    {
        $draft = $this->draft($this->brand(['enabled' => false]), [Platform::TikTok]);

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$draft->variants->first()->id] && $job->voiceover === false);
    }

    public function test_an_auto_publish_rule_can_switch_the_voice_off_for_one_channel(): void
    {
        $brand = $this->brand(['enabled' => true]);

        $draft = $this->draft($brand, [Platform::TikTok], channelOptions: ['tiktok' => ['settings' => ['voiceover' => 'off']]]);

        $this->assertSame('off', $draft->variants->first()->setting('voiceover'));
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->voiceover === false);
    }

    public function test_channels_that_want_the_same_video_share_one_render_and_the_others_get_their_own(): void
    {
        $brand = $this->brand(['enabled' => true]);
        $draft = $this->draft($brand, [Platform::InstagramBusiness, Platform::TikTok], render: false, channelOptions: ['ig_business' => ['format' => ContentFormat::Video]]);

        app(PrepareVariantMedia::class)->execute($draft->variants()->get());
        Queue::assertPushed(RenderVideoJob::class, 1);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->voiceover && count($job->variantIds) === 2);

        Queue::fake();
        $instagram = $draft->variants->firstWhere('platform', Platform::InstagramBusiness);
        $instagram->putSettings(['voiceover' => 'off']);

        app(PrepareVariantMedia::class)->execute($draft->variants()->get());
        Queue::assertPushed(RenderVideoJob::class, 2);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => ! $job->voiceover && $job->variantIds === [$instagram->id]);
    }

    public function test_a_video_that_has_a_voice_is_reused_only_by_a_channel_that_wants_one(): void
    {
        $brand = $this->brand(['enabled' => true]);
        $draft = $this->draft($brand, [Platform::TikTok], render: false);
        $tiktok = $draft->variants->first();
        $voiced = $this->video($brand, $draft, ['voiceover' => ['status' => 'ok']]);

        app(PrepareVariantMedia::class)->execute([$tiktok]);
        $this->assertSame([$voiced->id], $tiktok->media()->pluck('media_assets.id')->all(), 'the narrated video it asked for exists, so it is attached');
        Queue::assertNothingPushed();

        $tiktok->putSettings(['voiceover' => 'off']);
        app(PrepareVariantMedia::class)->execute([$tiktok->refresh()]);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => ! $job->voiceover, 'a silent channel must not be given the narrated video');
    }

    public function test_a_video_whose_voice_failed_is_not_reused_by_a_channel_that_wants_the_voice(): void
    {
        $brand = $this->brand(['enabled' => true]);
        $draft = $this->draft($brand, [Platform::TikTok], render: false);
        $silent = $this->video($brand, $draft, ['voiceover' => ['status' => 'failed', 'code' => 'quota_exceeded']]);

        app(PrepareVariantMedia::class)->execute($draft->variants()->get());

        // It renders again — the plan may have been renewed since — rather than settling for the silent one.
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->voiceover);
        $this->assertSame([], $draft->variants->first()->media()->pluck('media_assets.id')->all());
        $this->assertNotNull($silent);
    }

    public function test_an_older_video_without_any_voice_information_is_a_plain_video(): void
    {
        $brand = $this->brand(['enabled' => false]);
        $draft = $this->draft($brand, [Platform::TikTok], render: false);
        $old = $this->video($brand, $draft, []);

        app(PrepareVariantMedia::class)->execute($draft->variants()->get());

        $this->assertSame([$old->id], $draft->variants->first()->media()->pluck('media_assets.id')->all());
        Queue::assertNothingPushed();
    }

    public function test_switching_a_channels_voice_on_renders_for_that_channel_only(): void
    {
        $brand = $this->brand(['enabled' => false]);
        $draft = $this->draft($brand, [Platform::FacebookPage, Platform::TikTok], render: false);
        $tiktok = $draft->variants->firstWhere('platform', Platform::TikTok);

        app(ChangeVariantVoiceover::class)->execute($tiktok, true);

        $this->assertSame('on', $tiktok->refresh()->setting('voiceover'));
        Queue::assertPushed(RenderVideoJob::class, 1);
        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->variantIds === [$tiktok->id] && $job->voiceover);
    }

    public function test_switching_the_voice_back_off_picks_the_silent_video_that_already_exists(): void
    {
        $brand = $this->brand(['enabled' => true]);
        $draft = $this->draft($brand, [Platform::TikTok], render: false);
        $tiktok = $draft->variants->first();
        $silent = $this->video($brand, $draft, []);
        $this->video($brand, $draft, ['voiceover' => ['status' => 'ok']]);

        app(ChangeVariantVoiceover::class)->execute($tiktok, false);

        $this->assertSame('off', $tiktok->refresh()->setting('voiceover'));
        $this->assertSame([$silent->id], $tiktok->media()->pluck('media_assets.id')->all());
        Queue::assertNothingPushed();
    }

    public function test_the_voice_can_only_be_changed_where_it_can_be_heard_and_the_brand_has_one(): void
    {
        $brand = $this->brand(['enabled' => true]);
        $draft = $this->draft($brand, [Platform::FacebookPage, Platform::TikTok], render: false);
        $image = $draft->variants->firstWhere('platform', Platform::FacebookPage);
        $video = $draft->variants->firstWhere('platform', Platform::TikTok);

        try {
            app(ChangeVariantVoiceover::class)->execute($image, true);
            $this->fail('An image has no sound.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('samo za video', $e->getMessage());
        }

        $brand->update(['voiceover' => ['enabled' => true, 'voice_id' => null]]);

        try {
            app(ChangeVariantVoiceover::class)->execute($video->refresh(), true);
            $this->fail('A brand with no voice cannot be given one by a channel.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('glas', $e->getMessage());
        }

        $video->forceFill(['status' => VariantStatus::Published, 'external_post_id' => 'x'])->save();
        $this->expectException(InvalidArgumentException::class);
        app(ChangeVariantVoiceover::class)->execute($video->refresh(), false);
    }

    public function test_a_script_somebody_wrote_reaches_the_job_and_is_checked_before_it_is_queued(): void
    {
        $brand = $this->brand(['enabled' => true]);
        $draft = $this->draft($brand, [Platform::TikTok], render: false);
        $variant = $draft->variants->first();

        app(PrepareVariantMedia::class)->execute([$variant], fresh: true, video: ['voiceover' => true, 'script' => ['Prvi redak.', 'Drugi redak.', 'Treći redak.']]);

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->script === ['Prvi redak.', 'Drugi redak.', 'Treći redak.'] && $job->voiceover);

        Queue::fake();

        try {
            app(PrepareVariantMedia::class)->execute([$variant], fresh: true, video: ['voiceover' => true, 'script' => ['Samo za 0,01 €!', '', '']]);
            $this->fail('A price the offer does not carry must stop the render before it is queued.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('0,01 €', $e->getMessage());
        }

        Queue::assertNothingPushed();
    }

    public function test_a_render_queued_before_the_voice_existed_is_a_silent_render(): void
    {
        // What unserializing an older payload produces: no constructor, only declared defaults.
        $job = (new ReflectionClass(RenderVideoJob::class))->newInstanceWithoutConstructor();

        $this->assertFalse($job->voiceover);
        $this->assertNull($job->script);
    }

    /**
     * @param  array<string, mixed>  $voiceover
     */
    private function brand(array $voiceover = []): Brand
    {
        return Brand::factory()->create([
            'voice' => ['cta' => 'Preuzmi Listo'],
            'voiceover' => ['enabled' => false, 'voice_id' => 'voice-1', ...$voiceover],
        ]);
    }

    /**
     * @param  list<Platform>  $platforms
     * @param  array<string, array<string, mixed>>  $channelOptions
     */
    private function draft(Brand $brand, array $platforms, bool $render = true, array $channelOptions = []): PostDraft
    {
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Deal,
            'title' => 'Kruh bijeli 500 g',
            'subtitle' => 'Konzum',
            'price' => ['current_cents' => 149, 'old_cents' => 199, 'discount_pct' => 25, 'currency' => 'EUR', 'unit_label' => null],
            'facts' => [],
            'badges' => [],
        ]);

        $accounts = array_map(fn (Platform $platform): SocialAccount => SocialAccount::factory()->for($brand)->create(['platform' => $platform]), $platforms);

        $draft = app(CreateDraft::class)->execute($item, $accounts, status: DraftStatus::PendingApproval, render: $render, channelOptions: $channelOptions);

        return $draft->load('variants');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function video(Brand $brand, PostDraft $draft, array $params): MediaAsset
    {
        return MediaAsset::factory()->for($brand)->create([
            'post_draft_id' => $draft->id, 'template_key' => 'video/slideshow', 'format' => 'mp4',
            'width' => 1080, 'height' => 1920, 'duration_ms' => 9000, 'params' => $params,
        ]);
    }
}
