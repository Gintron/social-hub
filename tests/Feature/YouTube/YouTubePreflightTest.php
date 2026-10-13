<?php

declare(strict_types=1);

namespace Tests\Feature\YouTube;

use App\Models\PostVariant;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\YouTube\YouTubePreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesYouTubeVariants;
use Tests\TestCase;

final class YouTubePreflightTest extends TestCase
{
    use MakesYouTubeVariants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('youtube.privacy', 'public');

        Storage::fake('public');
    }

    public function test_a_vertical_video_with_a_short_title_passes_and_returns_its_video(): void
    {
        $variant = $this->youtubeVariant();

        $asset = app(YouTubePreflight::class)->assert($variant);

        $this->assertSame($variant->media->first()->id, $asset->id);
    }

    public function test_the_title_is_the_first_line_and_the_rest_is_the_description(): void
    {
        [$title, $description] = YouTubePreflight::split("  Naslov videa\n\n  Opis u dva reda.\nDrugi red.  ");

        $this->assertSame('Naslov videa', $title);
        $this->assertSame("Opis u dva reda.\nDrugi red.", $description);
    }

    public function test_a_title_longer_than_100_units_is_refused(): void
    {
        $this->assertRefused($this->youtubeVariant(['caption' => str_repeat('a', 101)."\n\nopis"]), 'title_too_long');
    }

    public function test_a_title_of_exactly_100_units_passes(): void
    {
        $variant = $this->youtubeVariant(['caption' => str_repeat('a', 100)."\n\nopis"]);

        $this->assertSame($variant->media->first()->id, app(YouTubePreflight::class)->assert($variant)->id);
    }

    public function test_an_emoji_in_the_title_counts_as_two_units(): void
    {
        // 50 emoji are 100 UTF-16 units; one more letter is one unit too many.
        $this->assertRefused($this->youtubeVariant(['caption' => str_repeat('🚀', 50).'a']), 'title_too_long');
    }

    public function test_an_empty_caption_has_no_title(): void
    {
        $this->assertRefused($this->youtubeVariant(['caption' => '']), 'empty_title');
    }

    public function test_angle_brackets_in_the_title_are_refused(): void
    {
        $this->assertRefused($this->youtubeVariant(['caption' => "Naslov <b>x</b>\n\nopis"]), 'title_invalid');
    }

    public function test_a_description_over_5000_bytes_is_refused(): void
    {
        $this->assertRefused($this->youtubeVariant(['caption' => "Naslov\n\n".str_repeat('x', 5001)]), 'description_too_long');
    }

    public function test_a_landscape_video_is_not_a_short(): void
    {
        $this->assertRefused($this->youtubeVariant(['width' => 1920, 'height' => 1080]), 'not_vertical');
    }

    public function test_a_video_longer_than_three_minutes_is_refused(): void
    {
        $this->assertRefused($this->youtubeVariant(['duration_ms' => 181_000]), 'video_too_long');
    }

    public function test_a_video_of_exactly_three_minutes_passes(): void
    {
        $variant = $this->youtubeVariant(['duration_ms' => 180_000]);

        $this->assertSame($variant->media->first()->id, app(YouTubePreflight::class)->assert($variant)->id);
    }

    public function test_an_unknown_duration_is_not_refused_because_the_hub_rendered_the_file(): void
    {
        $variant = $this->youtubeVariant(['duration_ms' => null]);

        $this->assertSame($variant->media->first()->id, app(YouTubePreflight::class)->assert($variant)->id);
    }

    public function test_two_files_are_refused(): void
    {
        $this->assertRefused($this->youtubeVariant(['assets' => 2]), 'youtube_needs_one_video');
    }

    public function test_a_video_that_is_not_on_disk_is_refused(): void
    {
        $this->assertRefused($this->youtubeVariant(['file' => false]), 'media_missing');
    }

    public function test_a_privacy_that_youtube_does_not_know_is_refused(): void
    {
        $this->assertRefused($this->youtubeVariant(['settings' => ['privacy' => 'friends']]), 'bad_privacy');
    }

    private function assertRefused(PostVariant $variant, string $code): void
    {
        try {
            app(YouTubePreflight::class)->assert($variant);
        } catch (PermanentPublishException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());
            $this->assertFalse($e->retryable);

            return;
        }

        $this->fail("Expected {$code}, nothing was refused.");
    }
}
