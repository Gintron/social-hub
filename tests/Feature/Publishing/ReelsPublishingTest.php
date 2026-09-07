<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Actions\CreateDraft;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Meta\FacebookPagePublisher;
use App\Publishing\Meta\InstagramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Reels on both Meta surfaces. They share the video but not the mechanics: Instagram takes a
 * container like any other post, Facebook wants an upload session on a separate host.
 */
final class ReelsPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_secret', 'app-secret');
        config()->set('meta.graph_version', 'v23.0');
        config()->set('hub.media_disk', 'public');
        Storage::fake('public');
        Sleep::fake();
    }

    public function test_instagram_publishes_a_reel_with_a_cover_from_the_first_slide(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && str_ends_with($path, '/media_publish')) {
                return Http::response(['id' => 'reel-media-1']);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/media')) {
                return Http::response(['id' => 'reel-container-1']);
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($path, 'content_publishing_limit')) {
                return Http::response(['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]]);
            }

            if (str_contains((string) ($query['fields'] ?? ''), 'status_code')) {
                return Http::response(['status_code' => 'FINISHED']);
            }

            return Http::response(['permalink' => 'https://www.instagram.com/reel/XYZ/']);
        });

        [$variant, $video, $poster] = $this->reelVariant(instagram: true);

        $result = app(InstagramPublisher::class)->publish($variant);

        $this->assertSame('reel-media-1', $result->externalId);
        $this->assertSame('https://www.instagram.com/reel/XYZ/', $result->permalink);

        Http::assertSent(function (Request $request) use ($video, $poster): bool {
            if ($request->method() !== 'POST' || ! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/media')) {
                return false;
            }

            $body = $request->data();

            return ($body['media_type'] ?? null) === 'REELS'
                && $body['video_url'] === $video->publicUrl()
                && $body['cover_url'] === $poster->publicUrl()
                && $body['share_to_feed'] === 'true';
        });
    }

    public function test_instagram_refuses_a_landscape_reel_before_calling_meta(): void
    {
        Http::fake();

        [$variant] = $this->reelVariant(instagram: true, width: 1920, height: 1080);

        try {
            app(InstagramPublisher::class)->publish($variant);
            $this->fail('Expected a preflight failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('reel_not_vertical', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_instagram_refuses_a_reel_shorter_than_three_seconds(): void
    {
        Http::fake();

        [$variant] = $this->reelVariant(instagram: true, durationMs: 1800);

        try {
            app(InstagramPublisher::class)->publish($variant);
            $this->fail('Expected a preflight failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('bad_video_duration', $e->errorCode);
        }
    }

    public function test_facebook_publishes_a_reel_through_an_upload_session(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'rupload.facebook.com')) {
                return Http::response(['success' => true]);
            }

            if (($request->data()['upload_phase'] ?? null) === 'start') {
                return Http::response(['video_id' => 'fb-reel-9', 'upload_url' => 'https://rupload.facebook.com/video-upload/v23.0/fb-reel-9']);
            }

            return Http::response(['success' => true]);
        });

        [$variant, $video] = $this->reelVariant(instagram: false);

        $result = app(FacebookPagePublisher::class)->publish($variant);

        $this->assertSame('fb-reel-9', $result->externalId);
        $this->assertSame('https://www.facebook.com/reel/fb-reel-9', $result->permalink);

        // The upload host takes the token in a header and the file as a URL, not as form fields.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'rupload.facebook.com')
            && $request->hasHeader('Authorization')
            && str_starts_with((string) $request->header('Authorization')[0], 'OAuth ')
            && $request->header('file_url')[0] === $video->publicUrl());

        Http::assertSent(fn (Request $request): bool => ($request->data()['upload_phase'] ?? null) === 'finish'
            && ($request->data()['video_state'] ?? null) === 'PUBLISHED'
            && str_contains((string) ($request->data()['description'] ?? ''), 'Konobar'));
    }

    public function test_facebook_refuses_a_reel_over_ninety_seconds(): void
    {
        Http::fake();

        [$variant] = $this->reelVariant(instagram: false, durationMs: 120_000);

        try {
            app(FacebookPagePublisher::class)->publish($variant);
            $this->fail('Expected a preflight failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('bad_video_duration', $e->errorCode);
            $this->assertStringContainsString('90 s', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * @return array{0: PostVariant, 1: MediaAsset, 2: MediaAsset}
     */
    private function reelVariant(bool $instagram, int $width = 1080, int $height = 1920, int $durationMs = 7800): array
    {
        $brand = Brand::factory()->create(['slug' => 'studentski-poslovi']);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['title' => 'Konobar/ica']);

        $account = $instagram
            ? SocialAccount::factory()->for($brand)->instagram()->create(['access_token' => 'ig-token'])
            : SocialAccount::factory()->for($brand)->create(['access_token' => 'page-token']);

        $draft = app(CreateDraft::class)->execute($item, [$account], render: false);
        $variant = $draft->variants->first();

        $poster = MediaAsset::factory()->for($brand)->create(['width' => $width, 'height' => $height, 'format' => 'jpg']);
        $video = MediaAsset::factory()->for($brand)->create([
            'width' => $width,
            'height' => $height,
            'format' => 'mp4',
            'duration_ms' => $durationMs,
            'poster_media_asset_id' => $poster->id,
            'path' => 'media/reel.mp4',
            'bytes' => 700_000,
        ]);

        $variant->media()->attach($video->id, ['position' => 0]);
        $variant->forceFill(['settings' => $instagram ? ['format' => 'reel'] : ['mode' => 'reel']])->save();

        return [$variant->fresh(['account', 'media', 'draft']), $video, $poster];
    }
}
