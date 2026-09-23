<?php

declare(strict_types=1);

namespace Tests\Feature\TikTok;

use App\Actions\CreateDraft;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\PublishLog;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\TokenInvalidException;
use App\Publishing\PublisherRegistry;
use App\Publishing\TikTok\Business\TikTokBusinessPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TikTok API for Business. Unlike the developer track there is no privacy wall and no container to
 * poll — one call publishes publicly — but a failure still arrives as HTTP 200 with a non-zero code.
 */
final class TikTokBusinessPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tiktok.driver', 'business');
        config()->set('tiktok.business.app_id', 'app-id');
        config()->set('tiktok.business.app_secret', 'app-secret');
    }

    public function test_it_reads_the_account_settings_then_publishes_publicly(): void
    {
        $this->fakeBusiness();

        [$variant, $video] = $this->variant();

        $result = app(TikTokBusinessPublisher::class)->publish($variant);

        $this->assertSame('v_pub_url~v1.123', $result->externalId);
        $this->assertFalse($result->handedToCreator);

        Http::assertSent(function (Request $request) use ($video): bool {
            if (! str_contains($request->url(), '/business/video/publish/')) {
                return false;
            }

            $body = $request->data();

            return $body['business_id'] === 'open-id-1'
                && $body['video_url'] === $video->publicUrl()
                && $body['post_info']['is_brand_organic'] === true
                && $body['post_info']['is_branded_content'] === false
                && str_contains($body['post_info']['caption'], 'Konobar');
        });

        $this->assertSame(
            ['tiktok.settings', 'tiktok.publish'],
            PublishLog::query()->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_the_token_travels_in_an_access_token_header(): void
    {
        $this->fakeBusiness();

        [$variant] = $this->variant();

        app(TikTokBusinessPublisher::class)->publish($variant);

        Http::assertSent(fn (Request $request): bool => $request->header('Access-Token') === ['tiktok-token']);
    }

    public function test_what_the_account_forbids_wins_over_the_default(): void
    {
        $this->fakeBusiness(settings: ['duet_disabled' => true, 'stitch_disabled' => true]);

        [$variant] = $this->variant();

        app(TikTokBusinessPublisher::class)->publish($variant);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/business/video/publish/')) {
                return false;
            }

            return $request->data()['post_info']['disable_duet'] === true
                && $request->data()['post_info']['disable_stitch'] === true;
        });
    }

    public function test_inbox_delivery_uploads_a_draft_and_sends_no_caption(): void
    {
        $this->fakeBusiness();

        [$variant] = $this->variant(['delivery' => 'inbox']);

        $result = app(TikTokBusinessPublisher::class)->publish($variant);

        $this->assertTrue($result->handedToCreator);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/business/video/publish/')) {
                return false;
            }

            // Everything else in post_info is ignored by TikTok when the post goes to drafts.
            return $request->data()['post_info'] === ['upload_to_draft' => true];
        });
    }

    public function test_a_paid_partnership_is_branded_content_not_brand_organic(): void
    {
        $this->fakeBusiness();

        [$variant] = $this->variant(['branded_content' => true]);

        app(TikTokBusinessPublisher::class)->publish($variant);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/business/video/publish/')) {
                return false;
            }

            return $request->data()['post_info']['is_branded_content'] === true
                && $request->data()['post_info']['is_brand_organic'] === false;
        });
    }

    public function test_a_failure_arrives_as_http_200_with_a_non_zero_code(): void
    {
        Http::fake([
            '*/business/video/settings/*' => Http::response(['code' => 0, 'data' => []]),
            '*/business/video/publish/' => Http::response(['code' => 40100, 'message' => 'Access token is invalid']),
        ]);

        [$variant] = $this->variant();

        $this->expectException(TokenInvalidException::class);

        app(TikTokBusinessPublisher::class)->publish($variant);
    }

    public function test_a_video_longer_than_the_account_allows_never_reaches_tiktok(): void
    {
        $this->fakeBusiness(settings: ['max_video_post_duration_sec' => 60]);

        [$variant] = $this->variant(durationMs: 90_000);

        $this->expectException(PermanentPublishException::class);

        try {
            app(TikTokBusinessPublisher::class)->publish($variant);
        } finally {
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/business/video/publish/'));
        }
    }

    public function test_photo_posts_are_sent_to_the_inbox_route_instead(): void
    {
        $this->fakeBusiness();

        [$variant] = $this->variant(['format' => 'carousel']);

        $this->expectException(PermanentPublishException::class);
        $this->expectExceptionMessageMatches('/foto objavu/');

        app(TikTokBusinessPublisher::class)->publish($variant);
    }

    public function test_the_registry_follows_the_configured_driver(): void
    {
        $this->assertInstanceOf(TikTokBusinessPublisher::class, app(PublisherRegistry::class)->for(Platform::TikTok));

        config()->set('tiktok.driver', 'developer');

        $this->assertInstanceOf(\App\Publishing\TikTok\TikTokPublisher::class, app(PublisherRegistry::class)->for(Platform::TikTok));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function fakeBusiness(array $settings = []): void
    {
        Http::fake([
            '*/business/video/settings/*' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'data' => $settings,
            ]),
            '*/business/video/publish/' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'data' => ['share_id' => 'v_pub_url~v1.123'],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: PostVariant, 1: MediaAsset}
     */
    private function variant(array $settings = [], int $durationMs = 7800): array
    {
        $brand = Brand::factory()->create(['slug' => 'studentski-poslovi']);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['title' => 'Konobar/ica']);

        $account = SocialAccount::factory()->for($brand)->create([
            'platform' => Platform::TikTok,
            'name' => '@studentskiposlovi',
            'external_id' => 'open-id-1',
            'access_token' => 'tiktok-token',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHours(20),
            'meta' => ['open_id' => 'open-id-1', 'api' => 'business'],
        ]);

        $draft = app(CreateDraft::class)->execute($item, [$account], render: false);
        $variant = $draft->variants->first();

        $video = MediaAsset::factory()->for($brand)->create([
            'width' => 1080,
            'height' => 1920,
            'format' => 'mp4',
            'duration_ms' => $durationMs,
            'path' => 'media/reel.mp4',
            'bytes' => 700_000,
        ]);

        $variant->media()->attach($video->id, ['position' => 0]);

        if ($settings !== []) {
            $variant->forceFill(['settings' => $settings])->save();
        }

        return [$variant->fresh(['account', 'media', 'draft']), $video];
    }
}
