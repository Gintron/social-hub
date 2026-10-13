<?php

declare(strict_types=1);

namespace Tests\Feature\YouTube;

use App\Actions\CollectPostMetrics;
use App\Enums\VariantStatus;
use App\Metrics\YouTubeMetrics;
use App\Models\PostVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesYouTubeVariants;
use Tests\TestCase;

final class YouTubeMetricsTest extends TestCase
{
    use MakesYouTubeVariants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('youtube.client_id', 'client-id');
        config()->set('youtube.client_secret', 'client-secret');

        Storage::fake('public');
    }

    public function test_the_numbers_under_a_short_come_from_its_statistics(): void
    {
        Http::fake([
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
                'id' => 'vid-123',
                'statistics' => ['viewCount' => '1500', 'likeCount' => '12', 'commentCount' => '3'],
            ]]], 200),
        ]);

        $snapshot = app(YouTubeMetrics::class)->fetch($this->publishedVariant());

        $this->assertSame(1500, $snapshot->views);
        $this->assertSame(12, $snapshot->likes);
        $this->assertSame(3, $snapshot->comments);
        $this->assertNull($snapshot->shares);
        $this->assertSame('vid-123', $snapshot->raw['video']['id']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'part=statistics')
            && str_contains($request->url(), 'id=vid-123'));
    }

    public function test_a_count_the_channel_hides_stays_unknown_rather_than_zero(): void
    {
        Http::fake([
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
                'id' => 'vid-123',
                'statistics' => ['viewCount' => '90'],
            ]]], 200),
        ]);

        $snapshot = app(YouTubeMetrics::class)->fetch($this->publishedVariant());

        $this->assertSame(90, $snapshot->views);
        $this->assertNull($snapshot->likes);
        $this->assertNull($snapshot->comments);
    }

    public function test_a_video_deleted_on_youtube_has_no_numbers_to_record(): void
    {
        Http::fake(['https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => []], 200)]);

        $this->assertNull(app(YouTubeMetrics::class)->fetch($this->publishedVariant()));
    }

    public function test_a_published_short_is_due_for_a_read(): void
    {
        $variant = $this->publishedVariant(publishedMinutesAgo: 60);

        $due = app(CollectPostMetrics::class)->due();

        $this->assertTrue($due->contains(fn (PostVariant $candidate): bool => $candidate->id === $variant->id));
    }

    private function publishedVariant(int $publishedMinutesAgo = 5): PostVariant
    {
        $variant = $this->youtubeVariant(['token_valid' => true]);
        $variant->forceFill([
            'status' => VariantStatus::Published,
            'external_post_id' => 'vid-123',
            'permalink' => 'https://www.youtube.com/shorts/vid-123',
            'published_at' => now()->subMinutes($publishedMinutesAgo),
        ])->save();

        return $variant->fresh(['account', 'media']);
    }
}
