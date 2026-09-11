<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Actions\ApproveDraft;
use App\Actions\CreateDraft;
use App\Actions\DispatchDraftPublishing;
use App\Actions\MarkManualPosted;
use App\Enums\AccountStatus;
use App\Enums\ActorType;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Jobs\PublishVariantJob;
use App\Jobs\RenderMediaJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PublishLog;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Notifications\AccountNeedsReconnect;
use App\Notifications\PublishFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

final class PublishFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_secret', 'app-secret');
        config()->set('meta.graph_version', 'v23.0');
        config()->set('hub.admin_emails', ['ops@example.test']);
        Storage::fake('public');
        config()->set('hub.media_disk', 'public');
    }

    public function test_create_draft_builds_a_variant_per_account_and_queues_rendering(): void
    {
        Queue::fake();

        [$brand, $item] = $this->brandWithItem();
        $page = SocialAccount::factory()->for($brand)->create();
        $group = SocialAccount::factory()->for($brand)->group()->create();

        $draft = app(CreateDraft::class)->execute($item, [$page, $group], ActorType::Human, actorId: 1);

        $this->assertSame(DraftStatus::PendingApproval, $draft->status);
        $this->assertCount(2, $draft->variants);
        $this->assertSame('Konobar/ica', $draft->title);
        $this->assertTrue($item->postDrafts()->whereKey($draft->id)->exists());

        $fb = $draft->variants->firstWhere('platform', Platform::FacebookPage);
        $this->assertStringContainsString('Konobar/ica', $fb->caption);
        $this->assertSame('https://example.test/posao/1', $fb->link_url);
        $this->assertSame('photo', $fb->setting('mode'));
        $this->assertNotNull($fb->idempotency_key);

        Queue::assertPushed(RenderMediaJob::class, fn (RenderMediaJob $job): bool => $job->templateKey === 'kinds/job-square' && count($job->variantIds) === 2);
    }

    public function test_create_draft_rejects_accounts_of_another_brand(): void
    {
        Queue::fake();
        [, $item] = $this->brandWithItem();
        $foreign = SocialAccount::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(CreateDraft::class)->execute($item, [$foreign]);
    }

    public function test_publishes_a_photo_post_to_the_facebook_page(): void
    {
        Notification::fake();
        Http::fake([
            'graph.facebook.com/v23.0/*/photos' => Http::response(['id' => '111', 'post_id' => '999_111']),
            'example.test/*' => Http::response('', 200),
        ]);

        [$brand, $item] = $this->brandWithItem();
        $page = SocialAccount::factory()->for($brand)->create(['external_id' => '999', 'access_token' => 'page-token']);

        $draft = app(CreateDraft::class)->execute($item, [$page], render: false);
        $variant = $draft->variants->first();
        $asset = $this->asset($brand, $draft);
        $variant->media()->attach($asset->id, ['position' => 0]);

        app(ApproveDraft::class)->execute($draft, approverId: null);
        $queued = app(DispatchDraftPublishing::class)->execute($draft->refresh());
        $this->assertSame(1, $queued);

        // Queue is sync in tests, so the job already ran.
        $variant->refresh();
        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('999_111', $variant->external_post_id);
        $this->assertSame('https://www.facebook.com/999_111', $variant->permalink);
        $this->assertSame(DraftStatus::Published, $draft->refresh()->status);

        Http::assertSent(function (Request $request) use ($asset): bool {
            $body = $request->data();

            return str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/v23.0/999/photos')
                && $body['access_token'] === 'page-token'
                && $body['appsecret_proof'] === hash_hmac('sha256', 'page-token', 'app-secret')
                && $body['url'] === $asset->publicUrl()
                && str_contains($body['caption'], 'Konobar/ica');
        });

        $log = PublishLog::query()->firstOrFail();
        $this->assertSame('[redacted]', $log->request['params']['access_token']);
        $this->assertSame(200, $log->http_status);
        Notification::assertNothingSent();
    }

    public function test_publishing_twice_does_not_post_twice(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => '1', 'post_id' => '9_1']),
            'example.test/*' => Http::response('', 200),
        ]);

        [$brand, $item] = $this->brandWithItem();
        $page = SocialAccount::factory()->for($brand)->create();
        $draft = app(CreateDraft::class)->execute($item, [$page], render: false);
        $variant = $draft->variants->first();

        app(ApproveDraft::class)->execute($draft, null);
        app(DispatchDraftPublishing::class)->execute($draft->refresh());
        $this->assertSame(0, app(DispatchDraftPublishing::class)->execute($draft->refresh()));

        (new PublishVariantJob($variant->id))->handle(app(\App\Publishing\PublisherRegistry::class), app(\App\Support\AdminNotifier::class), app(\App\Publishing\LinkPreflight::class));

        // Idempotency claim is about the Graph call, not the total request count — the link
        // preflight hits the item's own URL once per publish attempt, which is unrelated.
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_contains($request->url(), 'graph.facebook.com')));
        $this->assertSame(VariantStatus::Published, $variant->refresh()->status);
    }

    public function test_invalid_token_marks_account_for_reconnect_and_notifies(): void
    {
        Notification::fake();
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Error validating access token: Session has expired', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463, 'fbtrace_id' => 'abc'],
            ], 400),
            'example.test/*' => Http::response('', 200),
        ]);

        [$brand, $item] = $this->brandWithItem();
        $page = SocialAccount::factory()->for($brand)->create();
        $draft = app(CreateDraft::class)->execute($item, [$page], render: false);

        app(ApproveDraft::class)->execute($draft, null);
        app(DispatchDraftPublishing::class)->execute($draft->refresh());

        $variant = $draft->variants()->firstOrFail();
        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('token_invalid', $variant->error_code);
        $this->assertSame(AccountStatus::NeedsReconnect, $page->refresh()->status);
        $this->assertSame(DraftStatus::Failed, $draft->refresh()->status);

        Notification::assertSentOnDemand(AccountNeedsReconnect::class);
        Notification::assertSentOnDemand(PublishFailed::class);
    }

    public function test_expired_item_is_skipped_instead_of_published(): void
    {
        Http::fake();
        [$brand, $item] = $this->brandWithItem(['expires_at' => now()->subHour()]);
        $page = SocialAccount::factory()->for($brand)->create();
        $draft = app(CreateDraft::class)->execute($item, [$page], render: false);

        app(ApproveDraft::class)->execute($draft, null);
        app(DispatchDraftPublishing::class)->execute($draft->refresh());

        $variant = $draft->variants()->firstOrFail();
        $this->assertSame(VariantStatus::Skipped, $variant->status);
        $this->assertSame('content_expired', $variant->error_code);
        Http::assertNothingSent();
    }

    public function test_a_source_that_silently_dropped_the_item_is_caught_at_publish_time(): void
    {
        // expires_at still looks fine — the source archived it without ever telling the hub.
        Http::fake([
            'uselisto.test/*' => Http::response('', 404),
        ]);
        [$brand, $item] = $this->brandWithItem(['url' => 'https://uselisto.test/katalozi/konzum/gone']);
        $page = SocialAccount::factory()->for($brand)->create();
        $draft = app(CreateDraft::class)->execute($item, [$page], render: false);

        app(ApproveDraft::class)->execute($draft, null);
        app(DispatchDraftPublishing::class)->execute($draft->refresh());

        $variant = $draft->variants()->firstOrFail();
        $this->assertSame(VariantStatus::Skipped, $variant->status);
        $this->assertSame('dead_link', $variant->error_code);
        $this->assertStringContainsString('uselisto.test/katalozi/konzum/gone', (string) $variant->error_message);
    }

    public function test_a_reachable_link_publishes_normally(): void
    {
        Http::fake([
            'uselisto.test/*' => Http::response('', 200),
            'graph.facebook.com/*' => Http::response(['id' => '1', 'post_id' => '9_1']),
        ]);
        [$brand, $item] = $this->brandWithItem(['url' => 'https://uselisto.test/katalozi/konzum/still-live']);
        $page = SocialAccount::factory()->for($brand)->create();
        $draft = app(CreateDraft::class)->execute($item, [$page], render: false);

        app(ApproveDraft::class)->execute($draft, null);
        app(DispatchDraftPublishing::class)->execute($draft->refresh());

        $this->assertSame(VariantStatus::Published, $draft->variants()->firstOrFail()->status);
    }

    public function test_manual_group_variant_waits_for_a_human(): void
    {
        Http::fake();
        [$brand, $item] = $this->brandWithItem();
        $group = SocialAccount::factory()->for($brand)->group()->create();
        $draft = app(CreateDraft::class)->execute($item, [$group], render: false);

        app(ApproveDraft::class)->execute($draft, null);
        app(DispatchDraftPublishing::class)->execute($draft->refresh());

        $variant = $draft->variants()->firstOrFail();
        $this->assertSame(VariantStatus::ManualPending, $variant->status);
        Http::assertNothingSent();

        app(MarkManualPosted::class)->execute($variant, userId: null, permalink: 'https://www.facebook.com/groups/123/posts/456');

        $this->assertSame(VariantStatus::ManualDone, $variant->refresh()->status);
        $this->assertSame(DraftStatus::Published, $draft->refresh()->status);
    }

    public function test_publish_due_command_queues_scheduled_drafts(): void
    {
        Queue::fake();
        [$brand, $item] = $this->brandWithItem();
        $page = SocialAccount::factory()->for($brand)->create();

        $due = app(CreateDraft::class)->execute($item, [$page], scheduledAt: now()->subMinute()->toImmutable(), status: DraftStatus::Approved, render: false);
        $later = app(CreateDraft::class)->execute($item, [$page], scheduledAt: now()->addHour()->toImmutable(), status: DraftStatus::Approved, render: false);

        $this->assertSame(DraftStatus::Scheduled, $due->status);

        $this->artisan('hub:publish-due')->assertSuccessful();

        Queue::assertPushed(PublishVariantJob::class, 1);
        $this->assertSame(DraftStatus::Publishing, $due->refresh()->status);
        $this->assertSame(DraftStatus::Scheduled, $later->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $itemOverrides
     * @return array{0: Brand, 1: ContentItem}
     */
    private function brandWithItem(array $itemOverrides = []): array
    {
        $brand = Brand::factory()->create(['slug' => 'studentski-poslovi']);
        $source = Source::factory()->for($brand)->create();
        $item = ContentItem::factory()->for($source)->for($brand)->create(array_replace(['title' => 'Konobar/ica'], $itemOverrides));

        return [$brand, $item];
    }

    private function asset(Brand $brand, \App\Models\PostDraft $draft): MediaAsset
    {
        Storage::disk('public')->put('media/test.jpg', 'jpeg-bytes');

        return MediaAsset::query()->create([
            'brand_id' => $brand->id,
            'post_draft_id' => $draft->id,
            'template_key' => 'kinds/job-square',
            'params' => [],
            'width' => 1080,
            'height' => 1080,
            'format' => 'jpg',
            'disk' => 'public',
            'path' => 'media/test.jpg',
            'bytes' => 10,
        ]);
    }
}
