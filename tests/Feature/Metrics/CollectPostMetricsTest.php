<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Actions\CollectPostMetrics;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Filament\Pages\PostPerformance;
use App\Jobs\CollectPostMetricsJob;
use App\Metrics\TikTokMetrics;
use App\Models\Brand;
use App\Models\PostDraft;
use App\Models\PostMetric;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class CollectPostMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.graph_version', 'v23.0');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 12:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_instagram_numbers_survive_a_metric_meta_no_longer_answers(): void
    {
        $values = ['views' => 900, 'reach' => 700, 'likes' => 40, 'comments' => 5, 'saved' => 12];

        Http::fake(function (Request $request) use ($values) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $metric = (string) ($query['metric'] ?? '');

            // One retired name fails the whole batch; asked one by one, the rest still answer.
            if (str_contains($metric, ',') || $metric === 'shares') {
                return Http::response(['error' => ['message' => '(#100) metric must be one of the following values', 'type' => 'OAuthException', 'code' => 100]], 400);
            }

            return Http::response(['data' => [['name' => $metric, 'period' => 'lifetime', 'values' => [['value' => $values[$metric] ?? 0]]]]]);
        });

        $variant = $this->published(Platform::InstagramBusiness, '17900000000000001');

        $metric = app(CollectPostMetrics::class)->execute($variant);

        $this->assertSame(900, $metric->views);
        $this->assertSame(700, $metric->reach);
        $this->assertSame(12, $metric->saves);
        $this->assertNull($metric->shares, 'metrika koju Meta ne vraća ostaje prazna, nije nula');
        $this->assertSame(57, $metric->interactions());
    }

    public function test_a_facebook_reel_reads_plays_and_its_reactions(): void
    {
        Http::fake([
            'graph.facebook.com/v23.0/555/video_insights*' => Http::response(['data' => [
                ['name' => 'blue_reels_play_count', 'values' => [['value' => 4200]]],
                ['name' => 'post_impressions_unique', 'values' => [['value' => 3100]]],
            ]]),
            'graph.facebook.com/v23.0/555*' => Http::response(['likes' => ['summary' => ['total_count' => 88]], 'comments' => ['summary' => ['total_count' => 7]]]),
        ]);

        $variant = $this->published(Platform::FacebookPage, '555', ContentFormat::Video);

        $metric = app(CollectPostMetrics::class)->execute($variant);

        $this->assertSame(4200, $metric->views);
        $this->assertSame(3100, $metric->reach);
        $this->assertSame(88, $metric->likes);
        $this->assertSame(7, $metric->comments);
    }

    public function test_tiktok_looks_the_video_up_once_and_then_reads_its_counts(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/status/fetch/*' => Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7291234567890]], 'error' => ['code' => 'ok']]),
            'open.tiktokapis.com/v2/video/query/*' => Http::response(['data' => ['videos' => [['id' => 7291234567890, 'view_count' => 1500, 'like_count' => 90, 'comment_count' => 4, 'share_count' => 11]]], 'error' => ['code' => 'ok']]),
        ]);

        $variant = $this->published(Platform::TikTok, 'v_pub_123', ContentFormat::Carousel, VariantStatus::ManualDone);

        $first = app(CollectPostMetrics::class)->execute($variant);
        app(CollectPostMetrics::class)->execute($variant->refresh());

        $this->assertSame(1500, $first->views);
        $this->assertSame(11, $first->shares);
        $this->assertSame('7291234567890', $variant->refresh()->setting(TikTokMetrics::VIDEO_ID_SETTING));
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'status/fetch')));
    }

    public function test_an_inbox_upload_nobody_posted_yet_has_nothing_to_read(): void
    {
        Http::fake(['open.tiktokapis.com/*' => Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX'], 'error' => ['code' => 'ok']])]);

        $variant = $this->published(Platform::TikTok, 'v_pub_456', ContentFormat::Video, VariantStatus::ManualDone);

        $this->assertNull(app(CollectPostMetrics::class)->execute($variant));
        $this->assertSame(0, PostMetric::query()->count());
    }

    public function test_new_posts_are_read_often_old_ones_daily_and_groups_never(): void
    {
        $unread = $this->published(Platform::InstagramBusiness, '1');
        $readAnHourAgo = $this->published(Platform::InstagramBusiness, '2');
        $readSevenHoursAgo = $this->published(Platform::InstagramBusiness, '3');
        $oldReadSevenHoursAgo = $this->published(Platform::InstagramBusiness, '4', publishedAt: CarbonImmutable::now()->subDays(10));
        $tooOld = $this->published(Platform::InstagramBusiness, '5', publishedAt: CarbonImmutable::now()->subDays(40));
        $group = $this->published(Platform::FacebookGroup, 'manual');

        $this->reading($readAnHourAgo, hoursAgo: 1);
        $this->reading($readSevenHoursAgo, hoursAgo: 7);
        $this->reading($oldReadSevenHoursAgo, hoursAgo: 7);

        $due = app(CollectPostMetrics::class)->due()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$unread->id, $readSevenHoursAgo->id], $due);
        $this->assertNotContains($tooOld->id, $due);
        $this->assertNotContains($group->id, $due);
    }

    public function test_a_failed_read_is_logged_and_the_run_goes_on(): void
    {
        Log::spy();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 401)]);

        $variant = $this->published(Platform::InstagramBusiness, '17900000000000009');

        CollectPostMetricsJob::dispatchSync($variant->id);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'hub.metrics.failed')->once();
        $this->assertSame(0, PostMetric::query()->count());
    }

    public function test_the_performance_page_compares_formats(): void
    {
        config()->set('hub.admin_emails', ['ops@example.test']);

        $reel = $this->published(Platform::InstagramBusiness, '11', ContentFormat::Video);
        $image = $this->published(Platform::InstagramBusiness, '12');
        $this->reading($reel, hoursAgo: 1, views: 5000);
        $this->reading($image, hoursAgo: 1, views: 120);

        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']))
            ->get(PostPerformance::getUrl())
            ->assertOk()
            ->assertSee('Kanal i format')
            ->assertSee('5.000')
            ->assertSee('Najgledanije objave');
    }

    private function published(
        Platform $platform,
        string $externalId,
        ContentFormat $format = ContentFormat::Image,
        VariantStatus $status = VariantStatus::Published,
        ?CarbonImmutable $publishedAt = null,
    ): PostVariant {
        $brand = Brand::query()->first() ?? Brand::factory()->create();
        $account = SocialAccount::factory()->for($brand)->create(['platform' => $platform]);
        $draft = PostDraft::factory()->create(['brand_id' => $brand->id, 'title' => "Objava {$externalId}"]);

        return PostVariant::query()->create([
            'post_draft_id' => $draft->id,
            'social_account_id' => $account->id,
            'platform' => $platform,
            'caption' => 'Tekst',
            'settings' => ['format' => $format->value],
            'status' => $status,
            'external_post_id' => $externalId,
            'published_at' => $status === VariantStatus::Published ? ($publishedAt ?? CarbonImmutable::now()->subDay()) : null,
            'manual_posted_at' => $status === VariantStatus::ManualDone ? ($publishedAt ?? CarbonImmutable::now()->subDay()) : null,
        ]);
    }

    private function reading(PostVariant $variant, int $hoursAgo, int $views = 100): PostMetric
    {
        return PostMetric::query()->create([
            'post_variant_id' => $variant->id,
            'captured_at' => CarbonImmutable::now()->subHours($hoursAgo),
            'views' => $views,
            'likes' => 3,
        ]);
    }
}
