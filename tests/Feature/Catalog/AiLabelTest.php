<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\CreateDraft;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Publishing\Meta\InstagramPublisher;
use App\Publishing\TikTok\Business\TikTokBusinessPublisher;
use App\Publishing\TikTok\TikTokPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The voice of these videos is synthesised, so the networks that have a parameter for AI-generated content get it:
 * TikTok `is_ai_generated` (Business API) and `is_aigc` (Content Posting API), Instagram `is_ai_generated`.
 * Facebook's Reels API has none (docs/catalog-video.md); nothing is sent there.
 */
final class AiLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_secret', 'app-secret');
        config()->set('hub.media_disk', 'public');
        Storage::fake('public');
        Sleep::fake();
    }

    public function test_a_video_with_a_synthetic_voice_is_labelled_and_one_without_is_not(): void
    {
        [$voiced] = $this->variant(Platform::TikTok, voiced: true);
        [$silent] = $this->variant(Platform::TikTok, voiced: false);
        [$failed] = $this->variant(Platform::TikTok, voiced: false, params: ['voiceover' => ['status' => 'failed']]);

        $this->assertTrue($voiced->aiGenerated());
        $this->assertFalse($silent->aiGenerated());
        $this->assertFalse($failed->aiGenerated(), 'a video whose voice could not be made has no synthetic voice in it');
    }

    public function test_the_channel_can_decide_either_way_whatever_the_video_carries(): void
    {
        [$off] = $this->variant(Platform::TikTok, voiced: true, settings: ['ai_generated' => false]);
        [$on] = $this->variant(Platform::TikTok, voiced: false, settings: ['ai_generated' => true]);

        $this->assertFalse($off->aiGenerated());
        $this->assertTrue($on->aiGenerated());
    }

    public function test_tiktok_business_labels_the_post_as_ai_generated(): void
    {
        config()->set('tiktok.driver', 'business');
        Http::fake([
            '*/business/video/settings/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => []]),
            '*/business/video/publish/' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['share_id' => 'v_pub_url~v1.1']]),
        ]);

        [$variant] = $this->variant(Platform::TikTok, voiced: true);
        app(TikTokBusinessPublisher::class)->publish($variant);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/business/video/publish/')
            && ($request->data()['post_info']['is_ai_generated'] ?? null) === true);
    }

    public function test_tiktok_business_sends_no_label_for_a_video_without_a_synthetic_voice(): void
    {
        config()->set('tiktok.driver', 'business');
        Http::fake([
            '*/business/video/settings/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => []]),
            '*/business/video/publish/' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['share_id' => 'v_pub_url~v1.1']]),
        ]);

        [$variant] = $this->variant(Platform::TikTok, voiced: false);
        app(TikTokBusinessPublisher::class)->publish($variant);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/business/video/publish/')
            && ! array_key_exists('is_ai_generated', $request->data()['post_info']));
    }

    public function test_the_content_posting_api_gets_is_aigc(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_contains($request->url(), 'creator_info') => Http::response(['data' => ['creator_username' => 'listo', 'privacy_level_options' => ['SELF_ONLY'], 'max_video_post_duration_sec' => 600], 'error' => ['code' => 'ok']]),
                str_contains($request->url(), '/video/init/') => Http::response(['data' => ['publish_id' => 'p1'], 'error' => ['code' => 'ok']]),
                default => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['123']], 'error' => ['code' => 'ok']]),
            };
        });

        [$variant] = $this->variant(Platform::TikTok, voiced: true);
        app(TikTokPublisher::class)->publish($variant);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/video/init/')
            && ($request->data()['post_info']['is_aigc'] ?? null) === true);
    }

    public function test_instagram_sets_the_label_when_the_reel_container_is_made(): void
    {
        $this->fakeInstagram();

        [$variant] = $this->variant(Platform::InstagramBusiness, voiced: true);
        app(InstagramPublisher::class)->publish($variant);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/media')
            && ($request->data()['media_type'] ?? null) === 'REELS'
            && ($request->data()['is_ai_generated'] ?? null) === 'true');
    }

    public function test_instagram_does_not_label_a_reel_without_a_synthetic_voice(): void
    {
        $this->fakeInstagram();

        [$variant] = $this->variant(Platform::InstagramBusiness, voiced: false);
        app(InstagramPublisher::class)->publish($variant);

        Http::assertNotSent(fn (Request $request): bool => array_key_exists('is_ai_generated', $request->data()));
    }

    public function test_a_graph_version_that_does_not_know_the_parameter_does_not_hold_the_reel_back(): void
    {
        Log::spy();
        $this->fakeInstagram(rejectLabel: true);

        [$variant] = $this->variant(Platform::InstagramBusiness, voiced: true);
        $result = app(InstagramPublisher::class)->publish($variant);

        $this->assertSame('reel-media-1', $result->externalId, 'the Reel went out');
        $posts = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/media'));
        $this->assertCount(2, $posts, 'tried with the label, then without');
        $this->assertArrayNotHasKey('is_ai_generated', $posts->last()[0]->data());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'hub.ig.ai_label_rejected');
    }

    private function fakeInstagram(bool $rejectLabel = false): void
    {
        Http::fake(function (Request $request) use ($rejectLabel) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && str_ends_with($path, '/media_publish')) {
                return Http::response(['id' => 'reel-media-1']);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/media')) {
                if ($rejectLabel && array_key_exists('is_ai_generated', $request->data())) {
                    return Http::response(['error' => ['message' => '(#100) Invalid parameter', 'type' => 'OAuthException', 'code' => 100]], 400);
                }

                return Http::response(['id' => 'reel-container-1']);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($path, 'content_publishing_limit')) {
                return Http::response(['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]]);
            }

            if (str_contains((string) ($query['fields'] ?? ''), 'status_code')) {
                return Http::response(['status_code' => 'FINISHED']);
            }

            return Http::response(['permalink' => 'https://www.instagram.com/reel/XYZ/']);
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $params
     * @return array{0: PostVariant, 1: MediaAsset}
     */
    private function variant(Platform $platform, bool $voiced, array $settings = [], ?array $params = null): array
    {
        $brand = Brand::factory()->create(['slug' => 'brand-'.uniqid()]);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['title' => 'Konobar/ica']);
        $account = SocialAccount::factory()->for($brand)->create([
            'platform' => $platform,
            'access_token' => 'token',
            'token_expires_at' => now()->addHours(20),
            'meta' => ['open_id' => 'open-id-1', 'api' => 'business'],
        ]);

        $draft = app(CreateDraft::class)->execute($item, [$account], render: false);
        $variant = $draft->variants->first();

        $poster = MediaAsset::factory()->for($brand)->create(['width' => 1080, 'height' => 1920, 'format' => 'jpg']);
        $video = MediaAsset::factory()->for($brand)->create([
            'width' => 1080,
            'height' => 1920,
            'format' => 'mp4',
            'duration_ms' => 16_000,
            'poster_media_asset_id' => $poster->id,
            'path' => 'media/catalog.mp4',
            'bytes' => 3_000_000,
            'params' => $params ?? ($voiced ? ['voiceover' => ['status' => 'ok']] : null),
        ]);

        $variant->media()->attach($video->id, ['position' => 0]);
        $variant->forceFill(['settings' => ['format' => ContentFormat::Video->value, ...$settings]])->save();

        return [$variant->fresh(['account', 'media', 'draft']), $video];
    }
}
