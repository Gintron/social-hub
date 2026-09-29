<?php

declare(strict_types=1);

namespace Tests\Feature\Drafting;

use App\Actions\ApplyAutoPublishRules;
use App\Enums\ActorType;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Support\DailyPostLimit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `brands.daily_post_limit`: the brand's own ceiling, whichever rule or series fills the day.
 */
final class DailyPostLimitTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['example.test/*' => Http::response()]);
        // Monday noon in Zagreb, inside the posting window.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:00', 'Europe/Zagreb'));

        $this->brand = Brand::factory()->create([
            'slug' => 'uselisto',
            'daily_post_limit' => 3,
            'posting_windows' => [['from' => '08:00', 'to' => '20:00']],
        ]);
        $this->source = Source::factory()->for($this->brand)->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_brand_without_a_limit_has_no_ceiling(): void
    {
        $this->brand->forceFill(['daily_post_limit' => null])->save();

        $this->assertNull(app(DailyPostLimit::class)->remaining($this->brand, CarbonImmutable::now()));
        $this->assertTrue(app(DailyPostLimit::class)->hasRoom($this->brand, CarbonImmutable::now()));
    }

    public function test_it_counts_posts_that_go_out_on_the_brands_local_day(): void
    {
        $this->draft(DraftStatus::Scheduled, '2026-09-07 00:10');
        $this->draft(DraftStatus::Published, '2026-09-07 23:50');
        // Not on Monday in Zagreb, though 22:30 UTC on Sunday is Monday 00:30 in the other direction.
        $this->draft(DraftStatus::Scheduled, '2026-09-06 23:30');
        $this->draft(DraftStatus::Scheduled, '2026-09-08 00:10');
        // Not going out: waiting for approval, failed, skipped, discarded.
        foreach ([DraftStatus::PendingApproval, DraftStatus::Failed, DraftStatus::Skipped, DraftStatus::Discarded] as $status) {
            $this->draft($status, '2026-09-07 10:00');
        }

        $this->assertSame(1, app(DailyPostLimit::class)->remaining($this->brand, CarbonImmutable::now()));
        $this->assertSame(3, app(DailyPostLimit::class)->remaining($this->brand, CarbonImmutable::now()->addDays(3)));
    }

    public function test_a_post_published_on_the_spot_counts_on_the_day_it_was_made(): void
    {
        PostDraft::factory()->create(['brand_id' => $this->brand->id, 'status' => DraftStatus::Published, 'scheduled_at' => null]);

        $this->assertSame(2, app(DailyPostLimit::class)->remaining($this->brand, CarbonImmutable::now()));
    }

    public function test_automation_fills_only_what_is_left_of_the_day(): void
    {
        $this->draft(DraftStatus::Scheduled, '2026-09-07 09:00');
        $this->draft(DraftStatus::Published, '2026-09-07 08:30', ActorType::Human);
        $this->items(4);
        SocialAccount::factory()->for($this->brand)->create();
        $this->rule(dailyCap: 5);

        $this->assertSame(1, app(ApplyAutoPublishRules::class)->execute($this->source));
        $this->assertSame(0, app(ApplyAutoPublishRules::class)->execute($this->source), 'dan je pun');

        $this->assertSame(0, app(DailyPostLimit::class)->remaining($this->brand, CarbonImmutable::now()));
    }

    public function test_a_full_day_does_not_stop_the_next_one(): void
    {
        // 19:50 and an hour's delay: the first slot is tomorrow at 08:00.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 19:50', 'Europe/Zagreb'));
        foreach (range(1, 3) as $n) {
            $this->draft(DraftStatus::Scheduled, '2026-09-07 19:0'.$n);
        }
        $this->items(2);
        SocialAccount::factory()->for($this->brand)->create();
        $this->rule(delay: 60);

        $this->assertSame(2, app(ApplyAutoPublishRules::class)->execute($this->source));
        $this->assertSame(1, app(DailyPostLimit::class)->remaining($this->brand, CarbonImmutable::parse('2026-09-08 12:00', 'Europe/Zagreb')));
    }

    public function test_a_person_is_never_stopped_by_the_limit(): void
    {
        foreach (range(1, 3) as $n) {
            $this->draft(DraftStatus::Scheduled, '2026-09-07 1'.$n.':00');
        }

        $extra = $this->draft(DraftStatus::Scheduled, '2026-09-07 17:00', ActorType::Human);

        $this->assertSame(DraftStatus::Scheduled, $extra->status);
        $this->assertSame(0, app(DailyPostLimit::class)->remaining($this->brand, CarbonImmutable::now()), 'ne ide ispod nule');
    }

    private function draft(DraftStatus $status, string $localTime, ActorType $by = ActorType::System): PostDraft
    {
        return PostDraft::factory()->create([
            'brand_id' => $this->brand->id,
            'status' => $status,
            'scheduled_at' => CarbonImmutable::parse($localTime, 'Europe/Zagreb')->utc(),
            'created_by_type' => $by,
        ]);
    }

    private function items(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            ContentItem::factory()->for($this->source)->for($this->brand)->create([
                'title' => "Stavka {$i}",
                'priority' => 100 - $i,
                'expires_at' => now()->addDays(20),
                'images' => [],
                'tags' => [],
            ]);
        }
    }

    private function rule(?int $dailyCap = null, int $delay = 0): AutoPublishRule
    {
        return AutoPublishRule::query()->create([
            'source_id' => $this->source->id,
            'platform' => Platform::FacebookPage,
            'enabled' => true,
            'delay_minutes' => $delay,
            'daily_cap' => $dailyCap,
        ]);
    }
}
