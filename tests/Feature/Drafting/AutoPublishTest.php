<?php

declare(strict_types=1);

namespace Tests\Feature\Drafting;

use App\Enums\ActorType;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Jobs\SyncSourceJob;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FeedPayload;
use Tests\TestCase;

final class AutoPublishTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://site.test/api/social-feed';

    /**
     * The feed's current answer. One Http::fake reads this on every call, because registering a
     * second fake for the same URL does not replace the first one — stubs answer in the order they
     * were registered, so the original payload would keep winning.
     *
     * @var array<string, mixed>
     */
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        // Inside the posting window, so a scheduled slot is "now" and the assertions stay readable.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:00', 'Europe/Zagreb'));

        $this->payload = FeedPayload::page([]);
        Http::fake(fn () => Http::response($this->payload));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_nothing_is_scheduled_while_automation_is_off(): void
    {
        $this->fakeFeed(2);
        $source = $this->source();

        $this->sync($source);

        $this->assertSame(2, $source->contentItems()->count());
        $this->assertSame(0, PostDraft::query()->count());
    }

    public function test_an_enabled_rule_schedules_new_items_into_the_posting_window(): void
    {
        $this->fakeFeed(2);
        $source = $this->source();
        $page = SocialAccount::factory()->for($source->brand)->create();
        $this->rule($source, Platform::FacebookPage, delayMinutes: 30);

        $this->sync($source);

        $drafts = PostDraft::query()->with('variants')->orderBy('scheduled_at')->get();

        $this->assertCount(2, $drafts);
        $this->assertTrue($drafts->every(fn (PostDraft $draft): bool => $draft->status === DraftStatus::Scheduled));
        $this->assertTrue($drafts->every(fn (PostDraft $draft): bool => $draft->created_by_type === ActorType::System));

        // now + 30 min delay, then spaced 45 minutes apart.
        $this->assertSame(
            ['2026-09-07 12:30', '2026-09-07 13:15'],
            $drafts->map(fn (PostDraft $draft): string => $draft->scheduled_at->setTimezone('Europe/Zagreb')->format('Y-m-d H:i'))->all(),
        );

        $this->assertSame([$page->id], $drafts->first()->variants->pluck('social_account_id')->unique()->all());
    }

    public function test_a_rule_only_targets_its_own_platform(): void
    {
        $this->fakeFeed(1);
        $source = $this->source();
        $page = SocialAccount::factory()->for($source->brand)->create();
        SocialAccount::factory()->for($source->brand)->instagram()->create();
        SocialAccount::factory()->for($source->brand)->group()->create();
        $this->rule($source, Platform::FacebookPage);

        $this->sync($source);

        $variants = PostDraft::query()->firstOrFail()->variants;
        $this->assertCount(1, $variants);
        $this->assertSame($page->id, $variants->first()->social_account_id);
    }

    public function test_a_delayed_item_waits_for_the_window_to_reopen(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 19:50', 'Europe/Zagreb'));

        $this->fakeFeed(1);
        $source = $this->source();
        SocialAccount::factory()->for($source->brand)->create();
        $this->rule($source, Platform::FacebookPage, delayMinutes: 60);

        $this->sync($source);

        $this->assertSame(
            '2026-09-08 08:00',
            PostDraft::query()->firstOrFail()->scheduled_at->setTimezone('Europe/Zagreb')->format('Y-m-d H:i'),
        );
    }

    public function test_the_daily_cap_limits_how_much_automation_may_post(): void
    {
        $this->fakeFeed(5);
        $source = $this->source();
        SocialAccount::factory()->for($source->brand)->create();
        $this->rule($source, Platform::FacebookPage, dailyCap: 2);

        $this->sync($source);
        $this->assertSame(2, PostDraft::query()->count());

        // A later sync on the same day may not top up beyond the cap.
        $this->fakeFeed(5, startAt: 10);
        $this->sync($source->refresh());
        $this->assertSame(2, PostDraft::query()->count());

        // Tomorrow the allowance resets.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
        $this->fakeFeed(5, startAt: 20);
        $this->sync($source->refresh());
        $this->assertSame(4, PostDraft::query()->count());
    }

    public function test_items_already_in_a_draft_are_never_scheduled_twice(): void
    {
        $this->fakeFeed(2);
        $source = $this->source();
        SocialAccount::factory()->for($source->brand)->create();
        $this->rule($source, Platform::FacebookPage);

        $this->sync($source);
        $this->assertSame(2, PostDraft::query()->count());

        // Same feed again: the items are unchanged, so nothing new should be drafted.
        $this->sync($source->refresh());
        $this->assertSame(2, PostDraft::query()->count());
    }

    private function sync(Source $source): void
    {
        Queue::fake([\App\Jobs\RenderMediaJob::class]);
        (new SyncSourceJob($source->id))->handle(app(\App\Sources\SourceSyncer::class), app(\App\Actions\ApplyAutoPublishRules::class));
    }

    private function fakeFeed(int $count, int $startAt = 1): void
    {
        $items = [];

        for ($i = $startAt; $i < $startAt + $count; $i++) {
            $items[] = FeedPayload::item("job:{$i}", [
                'title' => "Oglas {$i}",
                'priority' => 100 - $i,
                'updated_at' => CarbonImmutable::now()->toIso8601ZuluString(),
                'expires_at' => CarbonImmutable::now()->addDays(10)->toIso8601ZuluString(),
            ]);
        }

        $this->payload = FeedPayload::page($items);
    }

    private function source(): Source
    {
        $brand = Brand::factory()->create([
            'slug' => 'studentski-poslovi',
            'timezone' => 'Europe/Zagreb',
            'posting_windows' => [['from' => '08:00', 'to' => '20:00']],
        ]);

        return Source::factory()->for($brand)->create(['base_url' => self::URL, 'secret' => 'token']);
    }

    private function rule(Source $source, Platform $platform, int $delayMinutes = 0, ?int $dailyCap = null): AutoPublishRule
    {
        return AutoPublishRule::query()->create([
            'source_id' => $source->id,
            'platform' => $platform,
            'enabled' => true,
            'delay_minutes' => $delayMinutes,
            'daily_cap' => $dailyCap,
        ]);
    }
}
