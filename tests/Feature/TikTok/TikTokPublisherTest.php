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
use App\Publishing\TikTok\TikTokPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * TikTok Direct Post. The rules that matter are TikTok's, not ours: ask the creator what is allowed
 * before posting, and accept that an unaudited app may only post privately.
 */
final class TikTokPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tiktok.client_key', 'key');
        config()->set('tiktok.client_secret', 'secret');
        Sleep::fake();
    }

    public function test_it_asks_what_the_creator_allows_then_posts_and_waits(): void
    {
        $this->fakeTikTok(statuses: ['PROCESSING_UPLOAD', 'PUBLISH_COMPLETE']);

        [$variant, $video] = $this->variant();

        $result = app(TikTokPublisher::class)->publish($variant);

        $this->assertSame('publish-1', $result->externalId);
        $this->assertSame('https://www.tiktok.com/@studentskiposlovi/video/7300', $result->permalink);

        // Creator info must be read before init: the allowed privacy levels come from there.
        $order = [];
        Http::assertSent(function (Request $request) use (&$order): bool {
            $order[] = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return true;
        });
        $this->assertSame(['query', 'init', 'fetch', 'fetch'], $order);

        Http::assertSent(function (Request $request) use ($video): bool {
            if (! str_contains($request->url(), '/video/init/')) {
                return false;
            }

            $body = $request->data();

            return $body['source_info']['source'] === 'PULL_FROM_URL'
                && $body['source_info']['video_url'] === $video->publicUrl()
                && $body['post_info']['privacy_level'] === 'SELF_ONLY'
                && str_contains($body['post_info']['title'], 'Konobar');
        });

        $this->assertSame(
            ['tiktok.creator_info', 'tiktok.init', 'tiktok.status', 'tiktok.status'],
            PublishLog::query()->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_a_privacy_level_the_creator_does_not_allow_falls_back_instead_of_failing(): void
    {
        $this->fakeTikTok(privacyOptions: ['SELF_ONLY']);

        [$variant] = $this->variant(settings: ['privacy_level' => 'PUBLIC_TO_EVERYONE']);

        app(TikTokPublisher::class)->publish($variant);

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/video/init/')
            || $request->data()['post_info']['privacy_level'] === 'SELF_ONLY');
    }

    public function test_a_public_level_is_used_once_the_creator_allows_it(): void
    {
        $this->fakeTikTok(privacyOptions: ['PUBLIC_TO_EVERYONE', 'SELF_ONLY']);

        [$variant] = $this->variant(settings: ['privacy_level' => 'PUBLIC_TO_EVERYONE']);

        app(TikTokPublisher::class)->publish($variant);

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/video/init/')
            || $request->data()['post_info']['privacy_level'] === 'PUBLIC_TO_EVERYONE');
    }

    public function test_a_video_longer_than_the_account_allows_is_refused(): void
    {
        $this->fakeTikTok(maxDuration: 60);

        [$variant] = $this->variant(durationMs: 90_000);

        try {
            app(TikTokPublisher::class)->publish($variant);
            $this->fail('Expected a permanent failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('video_too_long', $e->errorCode);
            $this->assertStringContainsString('60 s', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/video/init/'));
    }

    public function test_an_image_instead_of_a_video_is_refused(): void
    {
        Http::fake();

        [$variant] = $this->variant(format: 'jpg');

        try {
            app(TikTokPublisher::class)->publish($variant);
            $this->fail('Expected a permanent failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('not_video', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_an_expired_token_stops_before_the_api_call(): void
    {
        Http::fake();

        [$variant] = $this->variant();
        $variant->account->forceFill(['token_expires_at' => now()->subHour()])->save();

        try {
            app(TikTokPublisher::class)->publish($variant->fresh(['account', 'media']));
            $this->fail('Expected a permanent failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('token_expired', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_an_invalid_token_is_reported_as_a_token_problem(): void
    {
        Http::fake(fn () => Http::response(['error' => ['code' => 'access_token_invalid', 'message' => 'The access token is invalid']], 401));

        [$variant] = $this->variant();

        $this->expectException(TokenInvalidException::class);

        app(TikTokPublisher::class)->publish($variant);
    }

    public function test_the_unaudited_app_error_explains_itself(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'creator_info')) {
                return Http::response(['data' => ['creator_username' => 'x', 'privacy_level_options' => ['SELF_ONLY']], 'error' => ['code' => 'ok']]);
            }

            return Http::response([
                'error' => ['code' => 'unaudited_client_can_only_post_to_private_accounts', 'message' => 'Unaudited client'],
            ], 403);
        });

        [$variant] = $this->variant();

        try {
            app(TikTokPublisher::class)->publish($variant);
            $this->fail('Expected a permanent failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('tiktok_unaudited', $e->errorCode);
            $this->assertStringContainsString('audit', $e->getMessage());
        }
    }

    public function test_inbox_delivery_uploads_a_draft_and_hands_it_to_the_creator(): void
    {
        $this->fakeTikTok(statuses: ['PROCESSING_DOWNLOAD', 'SEND_TO_USER_INBOX']);

        [$variant, $video] = $this->variant(settings: ['delivery' => 'inbox']);

        $result = app(TikTokPublisher::class)->publish($variant);

        $this->assertTrue($result->handedToCreator);
        $this->assertSame('publish-1', $result->externalId);
        $this->assertNull($result->permalink, 'nije javno dok ga čovjek ne objavi');

        // Upload only: caption, privacy and the sound are chosen in the app, so no post_info.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/inbox/video/init/')
            && ! array_key_exists('post_info', $request->data())
            && $request->data()['source_info']['video_url'] === $video->publicUrl());

        // creator_info belongs to Direct Post; a brand may have granted only video.upload.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'creator_info')
            || str_contains($request->url(), '/publish/video/init/'));

        $this->assertSame(
            ['tiktok.inbox_init', 'tiktok.status', 'tiktok.status'],
            PublishLog::query()->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_an_inbox_upload_the_creator_already_posted_does_not_wait_for_the_timeout(): void
    {
        // The creator posted from the app between two polls, so the inbox state was never seen.
        $this->fakeTikTok(statuses: ['PROCESSING_DOWNLOAD', 'PUBLISH_COMPLETE']);

        [$variant] = $this->variant(settings: ['delivery' => 'inbox']);

        $result = app(TikTokPublisher::class)->publish($variant);

        $this->assertTrue($result->handedToCreator);
        $this->assertSame('publish-1', $result->externalId);
        Http::assertSentCount(3); // inbox init and two polls — no timeout, no second upload
    }

    public function test_a_carousel_goes_out_as_a_photo_post_with_tiktok_music(): void
    {
        $this->fakeTikTok();

        [$variant, $first] = $this->variant(settings: ['format' => 'carousel'], format: 'jpg');
        $second = MediaAsset::factory()->for($variant->draft->brand)->create(['width' => 1080, 'height' => 1920, 'format' => 'jpg', 'path' => 'media/slide-2.jpg']);
        $variant->media()->attach($second->id, ['position' => 1]);
        $variant->forceFill(['caption' => "Konobar/ica u Zagrebu 🍽️\n\nPrijavi se na studentski-poslovi.hr"])->save();

        $result = app(TikTokPublisher::class)->publish($variant->fresh(['account', 'media', 'draft']));

        $this->assertSame('https://www.tiktok.com/@studentskiposlovi/photo/7300', $result->permalink);
        $this->assertFalse($result->handedToCreator);

        Http::assertSent(function (Request $request) use ($first, $second): bool {
            if (! str_contains($request->url(), '/content/init/')) {
                return false;
            }

            $body = $request->data();

            return $body['media_type'] === 'PHOTO'
                && $body['post_mode'] === 'DIRECT_POST'
                && $body['source_info']['photo_images'] === [$first->publicUrl(), $second->publicUrl()]
                && $body['source_info']['photo_cover_index'] === 0
                && $body['post_info']['title'] === 'Konobar/ica u Zagrebu 🍽️'
                && str_contains($body['post_info']['description'], 'Prijavi se')
                && $body['post_info']['privacy_level'] === 'SELF_ONLY'
                && $body['post_info']['auto_add_music'] === true;
        });

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/video/init/'));
    }

    public function test_a_photo_post_can_go_to_the_inbox_too(): void
    {
        $this->fakeTikTok(statuses: ['SEND_TO_USER_INBOX']);

        [$variant] = $this->variant(settings: ['format' => 'image', 'delivery' => 'inbox'], format: 'jpg');

        $result = app(TikTokPublisher::class)->publish($variant);

        $this->assertTrue($result->handedToCreator);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/content/init/')
            && $request->data()['post_mode'] === 'MEDIA_UPLOAD'
            && ! array_key_exists('privacy_level', $request->data()['post_info']));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'creator_info'));
    }

    public function test_an_inbox_photo_without_text_sends_no_post_info(): void
    {
        $this->fakeTikTok(statuses: ['SEND_TO_USER_INBOX']);

        [$variant] = $this->variant(settings: ['format' => 'image', 'delivery' => 'inbox'], format: 'jpg');
        $variant->forceFill(['caption' => ''])->save();

        app(TikTokPublisher::class)->publish($variant->fresh(['account', 'media', 'draft']));

        // `post_info: []` would go out as a JSON array, which TikTok refuses.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/content/init/')
            && ! array_key_exists('post_info', $request->data()));
    }

    public function test_a_photo_title_is_cut_by_tiktoks_utf16_count(): void
    {
        $this->fakeTikTok();

        [$variant] = $this->variant(settings: ['format' => 'image'], format: 'jpg');
        // 50 emoji are 100 UTF-16 units; the title allows 90, so 45 survive.
        $variant->forceFill(['caption' => str_repeat('😀', 50)])->save();

        app(TikTokPublisher::class)->publish($variant->fresh(['account', 'media', 'draft']));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/content/init/')
            && $request->data()['post_info']['title'] === str_repeat('😀', 45));
    }

    public function test_a_photo_post_refuses_a_video(): void
    {
        Http::fake();

        [$variant] = $this->variant(settings: ['format' => 'image'], format: 'mp4');

        try {
            app(TikTokPublisher::class)->publish($variant);
            $this->fail('Expected a permanent failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('not_image', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_a_failed_publish_reports_the_reason(): void
    {
        $this->fakeTikTok(statuses: ['FAILED'], failReason: 'video_pull_failed');

        [$variant] = $this->variant();

        try {
            app(TikTokPublisher::class)->publish($variant);
            $this->fail('Expected a permanent failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('tiktok_publish_failed', $e->errorCode);
            $this->assertStringContainsString('video_pull_failed', $e->getMessage());
        }
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $privacyOptions
     */
    private function fakeTikTok(
        array $statuses = ['PUBLISH_COMPLETE'],
        array $privacyOptions = ['SELF_ONLY'],
        int $maxDuration = 600,
        ?string $failReason = null,
    ): void {
        $remaining = $statuses;

        Http::fake(function (Request $request) use (&$remaining, $privacyOptions, $maxDuration, $failReason) {
            $url = $request->url();

            if (str_contains($url, 'creator_info')) {
                return Http::response([
                    'data' => [
                        'creator_username' => 'studentskiposlovi',
                        'privacy_level_options' => $privacyOptions,
                        'comment_disabled' => false,
                        'duet_disabled' => false,
                        'stitch_disabled' => true,
                        'max_video_post_duration_sec' => $maxDuration,
                    ],
                    'error' => ['code' => 'ok'],
                ]);
            }

            if (str_contains($url, '/init/')) {
                return Http::response(['data' => ['publish_id' => 'publish-1'], 'error' => ['code' => 'ok']]);
            }

            $status = count($remaining) > 1 ? array_shift($remaining) : $remaining[0];

            return Http::response([
                'data' => array_filter([
                    'status' => $status,
                    'fail_reason' => $failReason,
                    'publicaly_available_post_id' => $status === 'PUBLISH_COMPLETE' ? ['7300'] : null,
                ]),
                'error' => ['code' => 'ok'],
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: PostVariant, 1: MediaAsset}
     */
    private function variant(array $settings = [], int $durationMs = 7800, string $format = 'mp4'): array
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
        ]);

        $draft = app(CreateDraft::class)->execute($item, [$account], render: false);
        $variant = $draft->variants->first();

        $video = MediaAsset::factory()->for($brand)->create([
            'width' => 1080,
            'height' => 1920,
            'format' => $format,
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
