<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\CreateDraft;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Jobs\LeaveTikTokCommentJob;
use App\Jobs\PublishVariantJob;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\PublishLog;
use App\Notifications\TikTokCommentNeeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesCatalogItems;
use Tests\TestCase;

/**
 * The video says "Poveznica do aplikacije je u komentaru", so a TikTok post gets that comment: from the API once the
 * app has the permission, and from an admin's hand with the exact text until then — either way the promise holds.
 */
final class TikTokFirstCommentTest extends TestCase
{
    use MakesCatalogItems;
    use RefreshDatabase;

    private const COMMENT = '👉 Preuzmi Listo: https://uselisto.com/app?utm_source=tiktok&utm_medium=social&utm_campaign=katalog-konzum-2026-10-07';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tiktok.driver', 'business');
        config()->set('tiktok.business.comments', true);
        config()->set('hub.admin_emails', ['ops@example.test']);
        Notification::fake();
    }

    public function test_it_finds_the_post_comments_on_it_and_checks_that_everyone_can_see_it(): void
    {
        $this->fakeTikTok();
        $variant = $this->published();

        $this->leaveComment($variant);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/business/publish/status/')
            && $request['business_id'] === 'open-id-1' && $request['publish_id'] === 'v_pub_url~v1.42');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/business/comment/create/')
            && $request->data() === ['business_id' => 'open-id-1', 'video_id' => '7300000000000000001', 'text' => self::COMMENT]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/business/comment/list/')
            && $request['comment_ids'] === '["c-1"]');

        $variant->refresh();
        $this->assertSame('posted', $variant->setting('first_comment_status'));
        $this->assertSame('c-1', $variant->setting('first_comment_id'));
        $this->assertSame('7300000000000000001', $variant->setting('tiktok_video_id'));
        $this->assertSame(['tiktok.publish_status', 'tiktok.comment', 'tiktok.comment_check'], PublishLog::query()->orderBy('id')->pluck('event')->all());
        Notification::assertNothingSent();
    }

    public function test_a_comment_is_left_once_however_many_times_the_job_runs(): void
    {
        $this->fakeTikTok();
        $variant = $this->published();

        $this->leaveComment($variant);
        $this->leaveComment($variant);

        Http::assertSentCount(3);
    }

    public function test_while_tiktok_has_not_made_the_post_public_it_waits_and_asks_again(): void
    {
        $this->fakeTikTok(post: null);
        $variant = $this->published();

        $job = (new LeaveTikTokCommentJob($variant->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(180);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/comment/create/'));
        Notification::assertNothingSent();
    }

    public function test_after_the_last_try_an_admin_is_given_the_text(): void
    {
        $this->fakeTikTok(post: null);
        $variant = $this->published();

        $job = (new LeaveTikTokCommentJob($variant->id))->withFakeQueueInteractions();
        $job->job->attempts = 8;
        app()->call([$job, 'handle']);

        $this->assertSame('manual', $variant->refresh()->setting('first_comment_status'));
        $this->assertSame('no_post_id', $variant->setting('first_comment_reason'));
        Notification::assertSentOnDemand(TikTokCommentNeeded::class, fn (TikTokCommentNeeded $n): bool => $n->text === self::COMMENT && $n->reason === 'no_post_id');
    }

    public function test_without_the_permission_the_admin_pastes_the_comment_and_nothing_is_called(): void
    {
        config()->set('tiktok.business.comments', false);
        Http::fake();
        $variant = $this->published();

        $this->leaveComment($variant);

        Http::assertNothingSent();
        $this->assertSame('manual', $variant->refresh()->setting('first_comment_status'));
        $this->assertSame('not_enabled', $variant->setting('first_comment_reason'));
        Notification::assertSentOnDemand(TikTokCommentNeeded::class, fn (TikTokCommentNeeded $n): bool => $n->text === self::COMMENT && $n->reason === 'not_enabled');
    }

    public function test_a_refusal_for_the_missing_permission_is_told_as_that(): void
    {
        Http::fake([
            '*/business/publish/status/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['status' => 'PUBLISH_COMPLETE', 'post_ids' => ['7300000000000000001']]]),
            '*/business/comment/create/' => Http::response(['code' => 40002, 'message' => 'The access token has no permission scope comment.list.manage'], 200),
        ]);
        $variant = $this->published();

        $this->leaveComment($variant);

        $this->assertSame('manual', $variant->refresh()->setting('first_comment_status'));
        Notification::assertSentOnDemand(TikTokCommentNeeded::class, fn (TikTokCommentNeeded $n): bool => $n->reason === 'permission');
    }

    public function test_a_comment_tiktok_hides_is_not_counted_as_posted(): void
    {
        $this->fakeTikTok(visibility: 'HIDDEN');
        $variant = $this->published();

        $this->leaveComment($variant);

        $this->assertSame('manual', $variant->refresh()->setting('first_comment_status'));
        $this->assertSame('hidden', $variant->setting('first_comment_reason'));
        Notification::assertSentOnDemand(TikTokCommentNeeded::class, fn (TikTokCommentNeeded $n): bool => $n->reason === 'hidden');
    }

    public function test_a_publishing_that_failed_at_tiktok_has_nothing_to_comment_on(): void
    {
        Http::fake(['*/business/publish/status/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['status' => 'FAILED', 'reason' => 'frame_rate_check_failed']])]);
        $variant = $this->published();

        $this->leaveComment($variant);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/comment/create/'));
        $this->assertSame('manual', $variant->refresh()->setting('first_comment_status'));
        $this->assertStringContainsString('frame_rate_check_failed', (string) $variant->setting('first_comment_reason'));
    }

    public function test_publishing_queues_the_comment_a_few_minutes_later_and_only_when_there_is_one(): void
    {
        Queue::fake();
        Http::fake([
            '*/business/video/settings/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => []]),
            '*/business/video/publish/' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['share_id' => 'v_pub_url~v1.42']]),
            // The leaflet's own page, which the preflight asks before every post.
            'uselisto.com/*' => Http::response('ok'),
        ]);
        $withComment = $this->readyToPublish();
        $without = $this->readyToPublish(comment: false);
        // Publishing takes a variant that has been queued for it.
        PostVariant::query()->whereIn('id', [$withComment->id, $without->id])->update(['status' => VariantStatus::Queued->value]);

        (new PublishVariantJob($withComment->id))->handle(...$this->publishDependencies());
        (new PublishVariantJob($without->id))->handle(...$this->publishDependencies());

        $this->assertSame(VariantStatus::Published, $withComment->refresh()->status);
        Queue::assertPushed(LeaveTikTokCommentJob::class, 1);
        Queue::assertPushed(LeaveTikTokCommentJob::class, fn (LeaveTikTokCommentJob $job): bool => $job->variantId === $withComment->id && $job->delay !== null);
    }

    /**
     * @return list<object>
     */
    private function publishDependencies(): array
    {
        return [
            app(\App\Publishing\PublisherRegistry::class), app(\App\Support\AdminNotifier::class),
            app(\App\Publishing\LinkPreflight::class), app(\App\Publishing\FormatCheck::class),
        ];
    }

    private function leaveComment(PostVariant $variant): void
    {
        $job = (new LeaveTikTokCommentJob($variant->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
    }

    /**
     * @param  string|null  $post  The id of the post TikTok reports; null while it is still processing.
     */
    private function fakeTikTok(?string $post = '7300000000000000001', string $visibility = 'PUBLIC'): void
    {
        Http::fake([
            '*/business/publish/status/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => $post === null
                ? ['status' => 'PROCESSING_DOWNLOAD']
                : ['status' => 'PUBLISH_COMPLETE', 'post_ids' => [$post]]]),
            '*/business/comment/create/' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['comment_id' => 'c-1', 'video_id' => (string) $post, 'text' => 'x']]),
            '*/business/comment/list/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['comments' => [['comment_id' => 'c-1', 'status' => $visibility]]]]),
        ]);
    }

    private function published(): PostVariant
    {
        $variant = $this->readyToPublish();
        $variant->forceFill(['status' => VariantStatus::Published, 'external_post_id' => 'v_pub_url~v1.42', 'published_at' => now()])->save();

        return $variant;
    }

    private function readyToPublish(bool $comment = true): PostVariant
    {
        $brand = Brand::factory()->create(['slug' => 'brand-'.uniqid(), 'name' => 'Listo', 'voice' => ['cta' => 'Preuzmi Listo']]);
        $item = $this->catalogItem($brand);
        $account = $this->catalogAccounts($brand, [Platform::TikTok])[0];
        $account->forceFill(['token_expires_at' => now()->addHours(20), 'meta' => ['open_id' => 'open-id-1', 'api' => 'business']])->save();

        $draft = app(CreateDraft::class)->execute($item, [$account], status: DraftStatus::Approved, render: false);
        $variant = $draft->variants->first();

        Storage::fake('public');
        $video = MediaAsset::factory()->for($brand)->create(['width' => 1080, 'height' => 1920, 'format' => 'mp4', 'duration_ms' => 16_000, 'path' => 'media/catalog.mp4', 'bytes' => 3_000_000]);
        $variant->media()->attach($video->id, ['position' => 0]);

        if (! $comment) {
            $variant->forgetSetting('first_comment');
        }

        return $variant->fresh(['account', 'media', 'draft']);
    }
}
