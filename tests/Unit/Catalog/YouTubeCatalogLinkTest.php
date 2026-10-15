<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\CatalogCopy;
use App\Catalog\TrackedLink;
use App\Enums\Platform;
use App\Models\PostVariant;
use Tests\TestCase;

final class YouTubeCatalogLinkTest extends TestCase
{
    public function test_a_youtube_video_says_its_link_is_in_the_description(): void
    {
        $this->assertSame(CatalogCopy::WHERE_DESCRIPTION, CatalogCopy::where(Platform::YouTube));
        $this->assertSame('Poveznica do aplikacije je u opisu.', CatalogCopy::linkSentence(CatalogCopy::WHERE_DESCRIPTION));
    }

    public function test_the_other_channels_keep_the_comment_and_the_bio_they_had(): void
    {
        $this->assertSame(CatalogCopy::WHERE_COMMENT, CatalogCopy::where(Platform::FacebookPage));
        $this->assertSame(CatalogCopy::WHERE_COMMENT, CatalogCopy::where(Platform::TikTok));

        config()->set('catalog_video.link_in.tiktok', 'bio');

        $this->assertSame(CatalogCopy::WHERE_BIO, CatalogCopy::where(Platform::TikTok));
    }

    public function test_a_channel_reads_back_the_place_it_was_given_description_included(): void
    {
        $youtube = new PostVariant(['platform' => Platform::YouTube, 'settings' => ['link_in' => 'description']]);
        $tiktok = new PostVariant(['platform' => Platform::TikTok, 'settings' => ['link_in' => 'bio']]);

        $this->assertSame(CatalogCopy::WHERE_DESCRIPTION, $youtube->linkIn());
        $this->assertSame(CatalogCopy::WHERE_BIO, $tiktok->linkIn());
    }

    public function test_a_channel_with_no_place_of_its_own_takes_its_platforms(): void
    {
        $this->assertSame(CatalogCopy::WHERE_DESCRIPTION, (new PostVariant(['platform' => Platform::YouTube, 'settings' => ['format' => 'video']]))->linkIn());
        $this->assertSame(CatalogCopy::WHERE_COMMENT, (new PostVariant(['platform' => Platform::FacebookPage, 'settings' => null]))->linkIn());
        // Something that is not a place is no choice either.
        $this->assertSame(CatalogCopy::WHERE_DESCRIPTION, (new PostVariant(['platform' => Platform::YouTube, 'settings' => ['link_in' => 'nowhere']]))->linkIn());
    }

    public function test_a_youtube_link_is_measured_as_youtube(): void
    {
        $this->assertSame('youtube', TrackedLink::source(Platform::YouTube));
    }
}
