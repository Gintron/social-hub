<?php

declare(strict_types=1);

namespace Tests\Feature\YouTube;

use App\Drafting\CaptionBuilder;
use App\Enums\Platform;
use App\Publishing\YouTube\YouTubePreflight;
use App\Support\Utf16;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesCatalogItems;
use Tests\TestCase;

/**
 * A new-catalog video on a YouTube channel: its link is in the description, which is clickable, so the
 * caption carries the tracked address and no sentence sends the viewer to a comment that is never posted.
 */
final class YouTubeCatalogTest extends TestCase
{
    use MakesCatalogItems;
    use RefreshDatabase;

    public function test_a_catalog_video_on_youtube_puts_its_link_in_the_description(): void
    {
        $item = $this->catalogItem();

        $caption = (new CaptionBuilder)->for(Platform::YouTube, $item, $item->brand);

        [$title, $description] = YouTubePreflight::split($caption);

        $this->assertLessThanOrEqual(100, Utf16::length($title));
        $this->assertStringContainsString('Poveznica do aplikacije je u opisu.', $description);
        $this->assertStringNotContainsString('u komentaru', $caption);
        $this->assertStringContainsString('🔗 https://', $description);
    }
}
