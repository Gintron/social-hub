<?php

declare(strict_types=1);

namespace Tests\Feature\Drafting;

use App\Actions\ApplyAutoPublishRules;
use App\Actions\ApproveDraft;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * One quote a day, at the same hour, waiting for a person: no code of its own, only the rule, the brand's
 * window and the shape of the feed (the nearest day has the highest priority and ends with its day).
 */
final class DailyQuoteTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['*' => Http::response('', 200)]);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 07:00', 'Europe/Zagreb'));

        $brand = Brand::factory()->create([
            'slug' => 'rijec',
            'timezone' => 'Europe/Zagreb',
            'posting_windows' => [['from' => '18:00', 'to' => '18:30']],
        ]);
        $this->source = Source::factory()->for($brand)->create();
        SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]);

        AutoPublishRule::query()->create([
            'source_id' => $this->source->id,
            'platform' => Platform::TikTok,
            'enabled' => true,
            'requires_approval' => true,
            'delay_minutes' => 0,
            'daily_cap' => 1,
            'format' => ContentFormat::Video,
        ]);

        // The feed's window: today and the days after, nearest first.
        foreach (['2026-10-13' => 1000, '2026-10-14' => 999, '2026-10-15' => 998] as $date => $priority) {
            ContentItem::factory()->for($this->source)->for($brand)->create([
                'external_id' => "verse:hr:{$date}",
                'kind' => ContentKind::Quote,
                'title' => "Stih za {$date}.",
                'subtitle' => 'Iv 3,16',
                'url' => "https://biblija.test/hr/{$date}",
                'priority' => $priority,
                'facts' => [],
                'badges' => [],
                'price' => null,
                'images' => [],
                'published_at' => "{$date}T00:00:00Z",
                'expires_at' => CarbonImmutable::parse("{$date} 23:59:59", 'Europe/Zagreb')->utc(),
            ]);
        }
    }

    public function test_the_days_verse_waits_for_approval_with_its_hour_already_set(): void
    {
        $this->assertSame(1, app(ApplyAutoPublishRules::class)->execute($this->source));

        $draft = PostDraft::query()->with(['variants', 'contentItems'])->sole();

        $this->assertSame(DraftStatus::PendingApproval, $draft->status);
        $this->assertSame('verse:hr:2026-10-13', $draft->contentItems->sole()->external_id);
        $this->assertSame('2026-10-13 18:00', $draft->scheduled_at->setTimezone('Europe/Zagreb')->format('Y-m-d H:i'));
        $this->assertSame('video', $draft->variants->sole()->settings['format']);
        $this->assertStringStartsWith("Stih za 2026-10-13.\n— Iv 3,16", $draft->variants->sole()->caption);
    }

    public function test_one_a_day_however_often_the_rules_run(): void
    {
        $this->assertSame(1, app(ApplyAutoPublishRules::class)->execute($this->source));
        $this->assertSame(0, app(ApplyAutoPublishRules::class)->execute($this->source));

        $this->assertSame(1, PostDraft::query()->count(), 'the other days of the window wait for their own day');
    }

    public function test_approving_keeps_the_hour_and_the_next_day_brings_the_next_verse(): void
    {
        app(ApplyAutoPublishRules::class)->execute($this->source);
        $first = PostDraft::query()->sole();

        app(ApproveDraft::class)->execute($first, null);

        $first->refresh();
        $this->assertSame(DraftStatus::Scheduled, $first->status);
        $this->assertSame('2026-10-13 18:00', $first->scheduled_at->setTimezone('Europe/Zagreb')->format('Y-m-d H:i'));

        $this->travelTo(CarbonImmutable::parse('2026-10-14 07:00', 'Europe/Zagreb'));

        $this->assertSame(1, app(ApplyAutoPublishRules::class)->execute($this->source));

        $second = PostDraft::query()->whereKeyNot($first->id)->with('contentItems')->sole();
        $this->assertSame(DraftStatus::PendingApproval, $second->status);
        $this->assertSame('verse:hr:2026-10-14', $second->contentItems->sole()->external_id);
        $this->assertSame('2026-10-14 18:00', $second->scheduled_at->setTimezone('Europe/Zagreb')->format('Y-m-d H:i'));
    }

    public function test_a_verse_nobody_approved_does_not_come_back_the_next_day(): void
    {
        app(ApplyAutoPublishRules::class)->execute($this->source);
        $missed = PostDraft::query()->sole();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 07:00', 'Europe/Zagreb'));
        app(ApplyAutoPublishRules::class)->execute($this->source);

        $this->assertSame(DraftStatus::PendingApproval, $missed->refresh()->status, 'it is left for the person to discard');
        $this->assertTrue($missed->contentItems->sole()->isExpired(), 'and PublishVariantJob skips an expired item');
        $this->assertSame(
            ['verse:hr:2026-10-14'],
            PostDraft::query()->whereKeyNot($missed->id)->with('contentItems')->get()->map(fn (PostDraft $draft): string => $draft->contentItems->sole()->external_id)->all(),
        );
    }
}
