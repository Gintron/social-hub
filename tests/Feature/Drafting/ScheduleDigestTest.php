<?php

declare(strict_types=1);

namespace Tests\Feature\Drafting;

use App\Actions\ScheduleDigest;
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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ScheduleDigestTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        // Monday, an hour before the digest is due.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 18:05', 'Europe/Zagreb'));

        $this->brand = Brand::factory()->create([
            'slug' => 'studentski-poslovi',
            'timezone' => 'Europe/Zagreb',
            'posting_windows' => [['from' => '07:30', 'to' => '09:00'], ['from' => '19:00', 'to' => '22:00']],
            'digest' => ['enabled' => true, 'days' => ['1', '4'], 'time' => '19:00', 'count' => 3, 'kind' => 'job'],
        ]);
        $this->source = Source::factory()->for($this->brand)->create();

        foreach (['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1] as $title => $priority) {
            $this->item($title, $priority);
        }

        SocialAccount::factory()->for($this->brand)->instagram()->create();
        SocialAccount::factory()->for($this->brand)->create(['platform' => Platform::TikTok]);
        SocialAccount::factory()->for($this->brand)->group()->create();
        SocialAccount::factory()->for($this->brand)->create(); // Facebook Page without a rule

        $this->rule(Platform::InstagramBusiness, ['share_to_feed' => true]);
        $this->rule(Platform::TikTok, ['delivery' => 'inbox']);
        $this->rule(Platform::FacebookGroup);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_digest_goes_to_the_enabled_automatic_channels_with_their_settings(): void
    {
        // A already had its own post; a weekly roundup still takes it.
        PostDraft::factory()->create(['brand_id' => $this->brand->id])->contentItems()->attach(ContentItem::query()->where('title', 'A')->value('id'), ['position' => 0]);

        $draft = app(ScheduleDigest::class)->execute($this->brand);

        $this->assertNotNull($draft);
        $this->assertSame(PostDraft::KIND_DIGEST, $draft->kind);
        $this->assertSame(DraftStatus::Scheduled, $draft->status);
        $this->assertSame('2026-09-14 19:00', $draft->scheduled_at->setTimezone('Europe/Zagreb')->format('Y-m-d H:i'));
        $this->assertSame(['A', 'B', 'C'], $draft->contentItems->pluck('title')->all());

        $platforms = $draft->variants->map(fn ($variant): string => $variant->platform->value)->sort()->values()->all();
        $this->assertSame(['ig_business', 'tiktok'], $platforms, 'ručna grupa i kanal bez pravila ne dobivaju pregled');

        $tiktok = $draft->variants->firstWhere('platform', Platform::TikTok);
        $this->assertSame(ContentFormat::Carousel, $tiktok->format());
        $this->assertSame('inbox', $tiktok->setting('delivery'));
    }

    public function test_it_waits_for_its_day_and_hour_and_runs_once(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 17:30', 'Europe/Zagreb'));
        $this->assertNull(app(ScheduleDigest::class)->execute($this->brand), 'prerano');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 18:30', 'Europe/Zagreb'));
        $this->assertNull(app(ScheduleDigest::class)->execute($this->brand), 'utorak nije dan pregleda');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 18:05', 'Europe/Zagreb'));
        $this->assertNotNull(app(ScheduleDigest::class)->execute($this->brand));
        $this->assertNull(app(ScheduleDigest::class)->execute($this->brand), 'isti dan samo jednom');

        $this->brand->forceFill(['digest' => ['enabled' => false, 'days' => [1, 2, 3, 4, 5, 6, 7]]])->save();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 18:30', 'Europe/Zagreb'));
        $this->assertNull(app(ScheduleDigest::class)->execute($this->brand->refresh()), 'isključeno');
    }

    public function test_items_from_this_weeks_digest_wait_a_week(): void
    {
        $monday = app(ScheduleDigest::class)->execute($this->brand);
        $this->assertSame(['A', 'B', 'C'], $monday->contentItems->pluck('title')->all());

        $this->item('E', 0);
        $this->item('F', 0);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 18:30', 'Europe/Zagreb'));

        $thursday = app(ScheduleDigest::class)->execute($this->brand);

        $this->assertEqualsCanonicalizing(['D', 'E', 'F'], $thursday->contentItems->pluck('title')->all());
    }

    private function item(string $title, int $priority): ContentItem
    {
        return ContentItem::factory()->for($this->source)->for($this->brand)->create([
            'kind' => ContentKind::Job,
            'title' => $title,
            'priority' => $priority,
            'expires_at' => CarbonImmutable::now()->addDays(20),
            'images' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    private function rule(Platform $platform, ?array $settings = null): AutoPublishRule
    {
        return AutoPublishRule::query()->create([
            'source_id' => $this->source->id,
            'platform' => $platform,
            'enabled' => true,
            'settings' => $settings,
        ]);
    }
}
