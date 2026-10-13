<?php

declare(strict_types=1);

namespace Tests\Feature\YouTube;

use App\Drafting\DigestSeries;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Publishing\PublisherRegistry;
use App\Publishing\YouTube\YouTubePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesYouTubeVariants;
use Tests\TestCase;

final class YouTubePlatformTest extends TestCase
{
    use MakesYouTubeVariants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_youtube_channel_takes_only_video_and_is_not_manual(): void
    {
        $this->assertSame([ContentFormat::Video], Platform::YouTube->formats());
        $this->assertSame(ContentFormat::Video, Platform::YouTube->defaultFormat());
        $this->assertSame('YouTube Shorts', Platform::YouTube->label());
        $this->assertFalse(Platform::YouTube->isManual());
    }

    public function test_only_youtube_trades_its_access_token_on_use(): void
    {
        $this->assertTrue(Platform::YouTube->refreshesTokenOnUse());
        $this->assertFalse(Platform::TikTok->refreshesTokenOnUse());
        $this->assertFalse(Platform::FacebookPage->refreshesTokenOnUse());
    }

    public function test_a_stored_photo_format_falls_back_to_video(): void
    {
        $variant = $this->youtubeVariant(['settings' => ['format' => 'carousel']]);

        $this->assertSame(ContentFormat::Video, $variant->format());
    }

    public function test_a_digest_series_that_asks_for_a_carousel_gives_youtube_the_video_it_takes(): void
    {
        $series = DigestSeries::fromArray([
            'key' => 'k1', 'enabled' => true, 'name' => 'Top akcije', 'days' => [3], 'time' => '18:30', 'count' => 5,
            'kind' => 'deal', 'tag' => 'kaufland', 'formats' => ['meta' => 'carousel', 'tiktok' => 'carousel'],
        ]);

        // A Short is the same slides moved into a video; a single picture would drop every item but the cover.
        $this->assertSame(ContentFormat::Video, $series->formatFor(Platform::YouTube));
    }

    public function test_the_registry_hands_youtube_to_its_publisher(): void
    {
        $this->assertInstanceOf(YouTubePublisher::class, app(PublisherRegistry::class)->for(Platform::YouTube));
    }

    public function test_an_expired_access_token_does_not_make_a_youtube_account_unusable(): void
    {
        $account = SocialAccount::factory()->create([
            'platform' => Platform::YouTube,
            'access_token' => 'stale',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->subHour(),
        ]);

        $this->assertTrue($account->isUsable());
    }

    public function test_a_youtube_account_without_its_grant_is_unusable(): void
    {
        $account = SocialAccount::factory()->create([
            'platform' => Platform::YouTube,
            'access_token' => 'stale',
            'refresh_token' => null,
            'token_expires_at' => now()->subHour(),
        ]);

        $this->assertFalse($account->isUsable());
    }

    public function test_a_facebook_page_with_an_expired_token_is_still_unusable(): void
    {
        $account = SocialAccount::factory()->create([
            'platform' => Platform::FacebookPage,
            'access_token' => 'stale',
            'token_expires_at' => now()->subHour(),
        ]);

        $this->assertFalse($account->isUsable());
    }
}
