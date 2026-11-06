<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Actions\ApproveDraft;
use App\Actions\CreateDraft;
use App\Actions\DispatchDraftPublishing;
use App\Enums\VariantStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\PublishLog;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Exceptions\RateLimitedException;
use App\Publishing\Exceptions\TransientPublishException;
use App\Publishing\Meta\InstagramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class InstagramPublisherTest extends TestCase
{
    use RefreshDatabase;

    private const IG_ID = '17841400000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_secret', 'app-secret');
        config()->set('meta.graph_version', 'v23.0');
        config()->set('hub.media_disk', 'public');
        Storage::fake('public');
        Sleep::fake();
    }

    public function test_publishes_a_single_image_after_the_container_finishes(): void
    {
        $this->fakeGraph(containerStatuses: ['IN_PROGRESS', 'FINISHED']);

        [$variant, $asset] = $this->variantWithMedia();

        $result = app(InstagramPublisher::class)->publish($variant);

        $this->assertSame('media-1', $result->externalId);
        $this->assertSame('https://www.instagram.com/p/ABC123/', $result->permalink);

        Http::assertSent(fn (Request $r): bool => $this->isPost($r, '/media')
            && $r->data()['image_url'] === $asset->publicUrl()
            && str_contains($r->data()['caption'], 'Konobar/ica')
            && ! isset($r->data()['media_type']));

        Http::assertSent(fn (Request $r): bool => $this->isPost($r, '/media_publish') && $r->data()['creation_id'] === 'container-1');

        // Polled twice: the first answer was IN_PROGRESS.
        Sleep::assertSlept(fn (): bool => true, 1);

        $events = PublishLog::query()->pluck('event')->all();
        $this->assertContains('ig.container', $events);
        $this->assertContains('ig.container_status', $events);
        $this->assertContains('ig.publish', $events);
    }

    public function test_publishes_a_carousel_in_slide_order(): void
    {
        $this->fakeGraph();

        [$variant] = $this->variantWithMedia(slides: 3);

        app(InstagramPublisher::class)->publish($variant);

        $children = [];
        Http::assertSent(function (Request $r) use (&$children): bool {
            if ($this->isPost($r, '/media') && ($r->data()['is_carousel_item'] ?? null) === 'true') {
                $children[] = $r->data()['image_url'];
            }

            return true;
        });

        $this->assertCount(3, $children);
        $this->assertSame($variant->media()->get()->map->publicUrl()->all(), $children);

        Http::assertSent(fn (Request $r): bool => $this->isPost($r, '/media')
            && ($r->data()['media_type'] ?? null) === 'CAROUSEL'
            && $r->data()['children'] === 'child-1,child-2,child-3');
    }

    public function test_rejects_a_wrong_aspect_ratio_before_calling_meta(): void
    {
        Http::fake();

        [$variant] = $this->variantWithMedia(state: 'story');

        try {
            app(InstagramPublisher::class)->publish($variant);
            $this->fail('Expected a preflight failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('bad_aspect_ratio', $e->errorCode);
            $this->assertStringContainsString('1080x1920', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_accepts_a_1080x1350_portrait_which_sits_exactly_on_the_4_by_5_limit(): void
    {
        $this->fakeGraph();

        [$variant] = $this->variantWithMedia();
        $variant->media()->first()->forceFill(['width' => 1080, 'height' => 1350])->save();

        $result = app(InstagramPublisher::class)->publish($variant->refresh());

        $this->assertSame('media-1', $result->externalId);
    }

    public function test_rejects_a_mixed_ratio_carousel(): void
    {
        Http::fake();

        [$variant] = $this->variantWithMedia();
        $variant->media()->attach(MediaAsset::factory()->portrait()->create(['brand_id' => $variant->draft->brand_id])->id, ['position' => 1]);

        $this->expectException(PermanentPublishException::class);
        $this->expectExceptionMessageMatches('/nemaju isti omjer/');

        app(InstagramPublisher::class)->publish($variant->refresh());
    }

    public function test_rejects_a_caption_over_the_limit(): void
    {
        Http::fake();

        [$variant] = $this->variantWithMedia();
        $variant->forceFill(['caption' => str_repeat('a', 2201)])->save();

        try {
            app(InstagramPublisher::class)->publish($variant);
            $this->fail('Expected a preflight failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('caption_too_long', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_rejects_too_many_hashtags(): void
    {
        Http::fake();

        [$variant] = $this->variantWithMedia();
        $variant->forceFill(['caption' => 'Posao '.implode(' ', array_map(fn (int $i): string => "#tag{$i}", range(1, 31)))])->save();

        try {
            app(InstagramPublisher::class)->publish($variant);
            $this->fail('Expected a preflight failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('too_many_hashtags', $e->errorCode);
        }
    }

    public function test_rejects_media_that_is_not_jpeg(): void
    {
        Http::fake();

        [$variant] = $this->variantWithMedia();
        $variant->media()->first()->forceFill(['format' => 'png'])->save();

        try {
            app(InstagramPublisher::class)->publish($variant->refresh());
            $this->fail('Expected a preflight failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('not_jpeg', $e->errorCode);
        }
    }

    public function test_stops_when_the_daily_quota_is_used_up(): void
    {
        $this->fakeGraph(quotaUsage: 100);

        [$variant] = $this->variantWithMedia();

        try {
            app(InstagramPublisher::class)->publish($variant);
            $this->fail('Expected a rate limit');
        } catch (RateLimitedException $e) {
            $this->assertSame('rate_limited', $e->errorCode);
            $this->assertTrue($e->retryable);
        }

        Http::assertNotSent(fn (Request $r): bool => $this->isPost($r, '/media_publish'));
    }

    public function test_a_container_error_fails_permanently(): void
    {
        $this->fakeGraph(containerStatuses: ['ERROR']);

        [$variant] = $this->variantWithMedia();

        try {
            app(InstagramPublisher::class)->publish($variant);
            $this->fail('Expected a permanent failure');
        } catch (PermanentPublishException $e) {
            $this->assertSame('ig_container_error', $e->errorCode);
        }

        Http::assertNotSent(fn (Request $r): bool => $this->isPost($r, '/media_publish'));
    }

    public function test_first_comment_is_posted_and_never_breaks_a_live_post(): void
    {
        $this->fakeGraph(commentFails: true);

        [$variant] = $this->variantWithMedia();
        $variant->forceFill(['settings' => ['first_comment' => '#posao #split']])->save();

        $result = app(InstagramPublisher::class)->publish($variant->refresh());

        $this->assertSame('media-1', $result->externalId);
        Http::assertSent(fn (Request $r): bool => $this->isPost($r, 'media-1/comments') && $r->data()['message'] === '#posao #split');
    }

    public function test_end_to_end_through_the_publish_job(): void
    {
        $this->fakeGraph();

        [$variant] = $this->variantWithMedia();
        $draft = $variant->draft;

        app(ApproveDraft::class)->execute($draft, null);
        app(DispatchDraftPublishing::class)->execute($draft->refresh());

        $variant->refresh();
        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('media-1', $variant->external_post_id);
        $this->assertSame('https://www.instagram.com/p/ABC123/', $variant->permalink);
    }

    /**
     * One fake covering the whole Instagram flow, routed by method and path.
     *
     * @param  list<string>  $containerStatuses
     */
    public function test_a_timed_out_publish_that_went_live_is_adopted_not_posted_twice(): void
    {
        $this->fakeGraph(publishes: ['timeout'], listings: ['live']);
        [$variant] = $this->variantWithMedia();

        $this->publishExpectingRetry($variant);
        $this->assertSame('container-1', $variant->fresh()->setting('ig_publish.container'));

        $result = app(InstagramPublisher::class)->publish($variant->fresh());

        $this->assertSame('live-1', $result->externalId);
        $this->assertSame(1, $this->sentCount('POST', '/media'), 'The retry must not build a second container.');
        $this->assertSame(1, PublishLog::query()->where('event', 'ig.publish')->count(), 'The retry must not publish again.');
        $this->assertNull($variant->fresh()->setting('ig_publish'));
    }

    public function test_a_timed_out_publish_that_did_not_go_live_reuses_its_container(): void
    {
        $this->fakeGraph(publishes: ['timeout', 'ok'], listings: ['empty']);
        [$variant] = $this->variantWithMedia();

        $this->publishExpectingRetry($variant);
        $result = app(InstagramPublisher::class)->publish($variant->fresh());

        $this->assertSame('media-1', $result->externalId);
        $this->assertSame(1, $this->sentCount('POST', '/media'));
        $this->assertSame(2, PublishLog::query()->where('event', 'ig.publish')->count());
    }

    public function test_a_refused_second_publish_of_a_reused_container_adopts_the_post_that_appeared_meanwhile(): void
    {
        $this->fakeGraph(publishes: ['timeout', 'refused'], listings: ['empty', 'live']);
        [$variant] = $this->variantWithMedia();

        $this->publishExpectingRetry($variant);
        $result = app(InstagramPublisher::class)->publish($variant->fresh());

        $this->assertSame('live-1', $result->externalId);
        $this->assertSame(1, $this->sentCount('POST', '/media'));
    }

    public function test_a_marker_for_a_caption_that_has_since_changed_is_dropped(): void
    {
        $this->fakeGraph(publishes: ['timeout', 'ok'], listings: ['empty']);
        [$variant] = $this->variantWithMedia();

        $this->publishExpectingRetry($variant);
        $variant->fresh()->update(['caption' => 'Potpuno nov tekst objave']);

        $result = app(InstagramPublisher::class)->publish($variant->fresh());

        $this->assertSame('media-1', $result->externalId);
        $this->assertSame(2, $this->sentCount('POST', '/media'), 'A new caption needs a new container.');
    }

    public function test_an_expired_container_is_replaced_on_retry(): void
    {
        $this->fakeGraph(containerStatuses: ['FINISHED', 'EXPIRED', 'FINISHED'], publishes: ['timeout', 'ok'], listings: ['empty']);
        [$variant] = $this->variantWithMedia();

        $this->publishExpectingRetry($variant);
        $result = app(InstagramPublisher::class)->publish($variant->fresh());

        $this->assertSame('media-1', $result->externalId);
        $this->assertSame(2, $this->sentCount('POST', '/media'));
    }

    public function test_a_carousel_retry_reuses_its_parent_container(): void
    {
        $this->fakeGraph(publishes: ['timeout', 'ok'], listings: ['empty']);
        [$variant] = $this->variantWithMedia(slides: 3);

        $this->publishExpectingRetry($variant);
        $result = app(InstagramPublisher::class)->publish($variant->fresh());

        $this->assertSame('media-1', $result->externalId);
        $this->assertSame(4, $this->sentCount('POST', '/media'), 'Three slides and one parent, once.');
    }

    private function publishExpectingRetry(PostVariant $variant): void
    {
        try {
            app(InstagramPublisher::class)->publish($variant);
            $this->fail('The first attempt should not succeed.');
        } catch (TransientPublishException) {
            // the variant is retried
        }
    }

    private function sentCount(string $method, string $pathEnd): int
    {
        return Http::recorded(fn (Request $r): bool => $r->method() === $method
            && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), $pathEnd))->count();
    }

    /**
     * @param  list<string>  $publishes  outcome of each media_publish call: ok, timeout or refused (the last one repeats)
     * @param  list<string>  $listings  answer to each read of the account's latest media: empty or live (the last one repeats)
     */
    private function fakeGraph(array $containerStatuses = ['FINISHED'], int $quotaUsage = 3, bool $commentFails = false, array $publishes = ['ok'], array $listings = ['empty']): void
    {
        $statuses = $containerStatuses;
        $childSeq = 0;
        $containerSeq = 0;
        $caption = '';
        $publishSeq = $publishes;
        $listSeq = $listings;

        Http::fake(function (Request $request) use (&$statuses, &$childSeq, &$containerSeq, &$caption, &$publishSeq, &$listSeq, $quotaUsage, $commentFails) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $data = $request->data();

            if ($request->method() === 'POST') {
                if (str_ends_with($path, '/media_publish')) {
                    $outcome = count($publishSeq) > 1 ? array_shift($publishSeq) : $publishSeq[0];

                    if ($outcome === 'timeout') {
                        throw new ConnectionException('cURL error 28: Operation timed out');
                    }

                    return $outcome === 'refused'
                        ? Http::response(['error' => ['message' => 'Media ID is not available', 'code' => 9007, 'error_subcode' => 2207027]], 400)
                        : Http::response(['id' => 'media-1']);
                }

                if (str_ends_with($path, '/comments')) {
                    return $commentFails
                        ? Http::response(['error' => ['message' => 'Missing permission', 'code' => 10]], 403)
                        : Http::response(['id' => 'comment-1']);
                }

                if (str_ends_with($path, '/media')) {
                    if (($data['is_carousel_item'] ?? null) === 'true') {
                        return Http::response(['id' => 'child-'.(++$childSeq)]);
                    }

                    $caption = $data['caption'] ?? $caption;

                    return Http::response(['id' => 'container-'.(++$containerSeq)]);
                }
            }

            if (str_contains($path, 'content_publishing_limit')) {
                return Http::response(['data' => [['quota_usage' => $quotaUsage, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]]);
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_ends_with($path, '/media') && str_contains((string) ($query['fields'] ?? ''), 'timestamp')) {
                $listing = count($listSeq) > 1 ? array_shift($listSeq) : $listSeq[0];

                return Http::response(['data' => $listing === 'live'
                    ? [['id' => 'live-1', 'caption' => $caption, 'timestamp' => now()->toIso8601String(), 'permalink' => 'https://www.instagram.com/reel/LIVE/']]
                    : []]);
            }

            if (str_contains((string) ($query['fields'] ?? ''), 'status_code')) {
                $status = count($statuses) > 1 ? array_shift($statuses) : $statuses[0];

                return Http::response(['id' => 'container-1', 'status_code' => $status, 'status' => $status === 'Ready' ? '' : $status]);
            }

            if (str_contains((string) ($query['fields'] ?? ''), 'permalink')) {
                return Http::response(['id' => 'media-1', 'permalink' => 'https://www.instagram.com/p/ABC123/']);
            }

            return Http::response(['id' => 'unknown']);
        });
    }

    /**
     * @return array{0: PostVariant, 1: MediaAsset}
     */
    private function variantWithMedia(int $slides = 1, string $state = 'square'): array
    {
        $brand = Brand::factory()->create(['slug' => 'studentski-poslovi']);
        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['title' => 'Konobar/ica']);
        $account = SocialAccount::factory()->for($brand)->instagram()->create(['external_id' => self::IG_ID, 'access_token' => 'ig-token']);

        $draft = app(CreateDraft::class)->execute($item, [$account], render: false);
        $variant = $draft->variants->first();

        $first = null;

        for ($i = 0; $i < $slides; $i++) {
            $factory = MediaAsset::factory()->for($brand)->state(['post_draft_id' => $draft->id]);
            $asset = ($state === 'story' ? $factory->story() : $factory)->create();
            $first ??= $asset;
            $variant->media()->attach($asset->id, ['position' => $i]);
        }

        return [$variant->refresh(), $first];
    }

    private function isPost(Request $request, string $pathSuffix): bool
    {
        return $request->method() === 'POST'
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), $pathSuffix);
    }
}
