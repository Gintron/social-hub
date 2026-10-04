<?php

declare(strict_types=1);

namespace Tests\Feature\Drafting;

use App\Actions\ApplyAutoPublishRules;
use App\Drafting\DigestBuilder;
use App\Enums\ContentKind;
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
 * An offer has to outlast its post by `hub.min_days_valid`: a shopper needs time to act on it.
 */
final class OfferShelfLifeTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['example.test/*' => Http::response()]);
        // Monday noon, inside the posting window: the first slot is "now".
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 12:00', 'Europe/Zagreb'));

        $this->brand = Brand::factory()->create(['slug' => 'uselisto', 'posting_windows' => [['from' => '08:00', 'to' => '20:00']]]);
        $this->source = Source::factory()->for($this->brand)->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_scope_keeps_offers_that_outlast_the_post_and_ignores_the_margin_for_other_kinds(): void
    {
        $this->item('Istječe sutra', 10, now()->addDay());
        $this->item('Istječe za dva dana', 10, now()->addDays(2));
        $this->item('Točno tri dana', 10, now()->addDays(3));
        $this->item('Bez roka', 10, null);
        $this->item('Oglas koji istječe sutra', 10, now()->addDay(), ContentKind::Job);
        $this->item('Već istekla', 10, now()->subMinute());

        $titles = ContentItem::query()->postableAt(CarbonImmutable::now())->orderBy('id')->pluck('title')->all();

        $this->assertSame(['Točno tri dana', 'Bez roka', 'Oglas koji istječe sutra'], $titles);
    }

    public function test_the_margin_counts_from_when_the_post_goes_out_not_from_now(): void
    {
        $this->item('Vrijedi 4 dana', 10, now()->addDays(4));
        $this->item('Vrijedi 9 dana', 10, now()->addDays(9));

        $inTwoDays = ContentItem::query()->postableAt(CarbonImmutable::now()->addDays(2))->pluck('title')->all();

        $this->assertSame(['Vrijedi 9 dana'], $inTwoDays, 'objava za dva dana traži još tri dana nakon toga');
    }

    public function test_an_item_is_checked_the_same_way_in_hand(): void
    {
        $deal = $this->item('Akcija', 10, now()->addDays(3));

        $this->assertTrue($deal->isPostableAt(CarbonImmutable::now()));
        $this->assertFalse($deal->isPostableAt(CarbonImmutable::now()->addMinute()));
        $this->assertTrue($this->item('Bez roka', 10, null)->isPostableAt(CarbonImmutable::now()->addYear()));
        $this->assertTrue($this->item('Oglas', 10, now()->addHour(), ContentKind::Job)->isPostableAt(CarbonImmutable::now()));
        $this->assertFalse($this->item('Istekla', 10, now()->subMinute(), ContentKind::Job)->isPostableAt(CarbonImmutable::now()));
    }

    public function test_automation_passes_over_an_offer_that_ends_too_soon(): void
    {
        $this->item('Kratko', 90, now()->addDays(2));
        $this->item('Dovoljno', 80, now()->addDays(10));
        $this->item('Bez roka', 70, null);
        SocialAccount::factory()->for($this->brand)->create();
        $this->rule(dailyCap: 2);

        app(ApplyAutoPublishRules::class)->execute($this->source);

        $this->assertSame(['Dovoljno', 'Bez roka'], $this->draftedTitles());
        $this->assertNull(ContentItem::query()->where('title', 'Kratko')->first()->postDrafts()->first());
    }

    public function test_each_item_is_held_to_the_slot_it_gets(): void
    {
        // Ends 3 days and 30 minutes from now: long enough for the 12:00 post, not for the 12:45 one.
        $this->item('Prva', 90, now()->addDays(20));
        $this->item('Na rubu', 80, now()->addDays(3)->addMinutes(30));
        $this->item('Treća', 70, now()->addDays(20));
        SocialAccount::factory()->for($this->brand)->create();
        $this->rule(dailyCap: 2);

        app(ApplyAutoPublishRules::class)->execute($this->source);

        $this->assertSame(['Prva', 'Treća'], $this->draftedTitles());
    }

    public function test_a_job_that_ends_tomorrow_is_still_posted(): void
    {
        $this->item('Posao', 90, now()->addDay(), ContentKind::Job);
        SocialAccount::factory()->for($this->brand)->create();
        $this->rule();

        app(ApplyAutoPublishRules::class)->execute($this->source);

        $this->assertSame(['Posao'], $this->draftedTitles());
    }

    public function test_a_roundup_only_takes_offers_that_outlast_its_post(): void
    {
        $this->item('Kratko', 90, now()->addDays(4));
        $this->item('Na rubu', 80, now()->addDays(5));
        $this->item('Dugo', 70, now()->addDays(15));

        $picked = app(DigestBuilder::class)->pick($this->brand, ContentKind::Deal, 5, postedAt: CarbonImmutable::now()->addDays(2));

        $this->assertSame(['Na rubu', 'Dugo'], $picked->pluck('title')->all());
    }

    public function test_a_roundup_scheduled_for_later_is_picked_for_that_time(): void
    {
        $this->item('Kratko', 90, now()->addDays(4));
        $this->item('Dugo', 80, now()->addDays(15));
        $this->item('Dugo 2', 70, now()->addDays(16));
        $account = SocialAccount::factory()->for($this->brand)->create();

        $draft = app(DigestBuilder::class)->build(
            $this->brand,
            ContentKind::Deal,
            [$account],
            count: 3,
            scheduledAt: CarbonImmutable::now()->addDays(2),
            render: false,
        );

        $this->assertSame(['Dugo', 'Dugo 2'], $draft->contentItems->pluck('title')->all());
    }

    /**
     * @return list<string>
     */
    private function draftedTitles(): array
    {
        return PostDraft::query()->with('contentItems')->orderBy('scheduled_at')->get()
            ->flatMap(fn (PostDraft $draft) => $draft->contentItems->pluck('title'))
            ->all();
    }

    private function item(string $title, int $priority, mixed $expiresAt, ContentKind $kind = ContentKind::Deal): ContentItem
    {
        return ContentItem::factory()->for($this->source)->for($this->brand)->create([
            'kind' => $kind,
            'title' => $title,
            'priority' => $priority,
            'expires_at' => $expiresAt,
            'images' => [],
            'tags' => [],
        ]);
    }

    private function rule(?int $dailyCap = null): AutoPublishRule
    {
        return AutoPublishRule::query()->create([
            'source_id' => $this->source->id,
            'platform' => Platform::FacebookPage,
            'enabled' => true,
            'requires_approval' => false,
            'daily_cap' => $dailyCap,
        ]);
    }
}
