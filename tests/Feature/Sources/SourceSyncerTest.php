<?php

declare(strict_types=1);

namespace Tests\Feature\Sources;

use App\Jobs\SyncSourceJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use App\Sources\SourceSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FeedPayload;
use Tests\TestCase;

final class SourceSyncerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://site.test/api/social-feed';

    public function test_sync_is_idempotent_and_tracks_changes(): void
    {
        // A single fake whose payload we swap between syncs: a second Http::fake() call would not
        // override the first stub (stubs are matched in registration order).
        $payload = FeedPayload::page([
            FeedPayload::item('job:1'),
            FeedPayload::item('job:2', ['title' => 'Kuhar/ica']),
        ]);
        Http::fake(function () use (&$payload) {
            return Http::response($payload);
        });

        $source = $this->source();
        $syncer = app(SourceSyncer::class);

        $first = $syncer->sync($source);
        $this->assertSame([2, 0, 0], [$first->created, $first->updated, $first->unchanged]);
        $this->assertSame(2, ContentItem::query()->count());

        $second = $syncer->sync($source->refresh());
        $this->assertSame([0, 0, 2], [$second->created, $second->updated, $second->unchanged]);
        $this->assertSame(2, ContentItem::query()->count());

        $payload = FeedPayload::page([
            FeedPayload::item('job:1', ['title' => 'Konobar/ica – hitno', 'updated_at' => '2026-09-06T07:10:00Z']),
        ]);

        $third = $syncer->sync($source->refresh());
        $this->assertSame([0, 1, 0], [$third->created, $third->updated, $third->unchanged]);

        $item = ContentItem::query()->where('external_id', 'job:1')->firstOrFail();
        $this->assertSame('Konobar/ica – hitno', $item->title);
        $this->assertSame($source->brand_id, $item->brand_id);
        $this->assertNotNull($source->refresh()->last_synced_at);
        $this->assertNull($source->last_error);
    }

    public function test_second_sync_sends_since_with_overlap(): void
    {
        Http::fake([self::URL.'*' => Http::response(FeedPayload::page([]))]);

        $source = $this->source();
        $syncer = app(SourceSyncer::class);

        $syncer->sync($source);
        $syncer->sync($source->refresh());

        $requests = [];
        Http::assertSent(function (Request $request) use (&$requests): bool {
            $requests[] = $request->url();

            return true;
        });

        $this->assertStringNotContainsString('since=', $requests[0]);
        $this->assertStringContainsString('since=', $requests[1]);
    }

    public function test_failure_is_recorded_on_the_source(): void
    {
        Http::fake([self::URL.'*' => Http::response('boom', 500)]);

        $source = $this->source();

        try {
            app(SourceSyncer::class)->sync($source);
            $this->fail('Expected exception');
        } catch (\App\Sources\Exceptions\SourceRequestException) {
            // expected
        }

        $this->assertStringContainsString('HTTP 500', (string) $source->refresh()->last_error);
        $this->assertNull($source->last_synced_at);
    }

    public function test_command_queues_a_job_per_enabled_pull_source(): void
    {
        Queue::fake();

        $enabled = $this->source();
        Source::factory()->for($enabled->brand)->create(['enabled' => false]);

        $this->artisan('hub:sync-sources')->assertSuccessful();

        Queue::assertPushed(SyncSourceJob::class, 1);
        Queue::assertPushed(SyncSourceJob::class, fn (SyncSourceJob $job): bool => $job->sourceId === $enabled->id);
    }

    public function test_command_can_run_inline(): void
    {
        Http::fake([self::URL.'*' => Http::response(FeedPayload::page([FeedPayload::item('job:7')]))]);

        $source = $this->source();

        $this->artisan('hub:sync-sources', ['--now' => true, '--source' => $source->name])
            ->expectsOutputToContain('1 new')
            ->assertSuccessful();

        $this->assertDatabaseHas('content_items', ['external_id' => 'job:7']);
    }

    public function test_source_test_command_prints_samples(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(FeedPayload::page([FeedPayload::item('job:1')])),
            'https://example.test/images/1.webp' => Http::response('', 200, ['Content-Type' => 'image/webp']),
        ]);

        $source = $this->source();

        $this->artisan('hub:source-test', ['source' => $source->name])
            ->expectsOutputToContain('Feed OK')
            ->expectsOutputToContain('job:1')
            ->assertSuccessful();
    }

    private function source(): Source
    {
        $brand = Brand::factory()->create(['slug' => 'studentski-poslovi']);

        return Source::factory()->for($brand)->create(['base_url' => self::URL, 'secret' => 'secret-token']);
    }
}
