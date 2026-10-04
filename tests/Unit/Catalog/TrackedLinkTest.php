<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\CatalogDemo;
use App\Catalog\TrackedLink;
use App\Enums\Platform;
use PHPUnit\Framework\TestCase;

/**
 * One link per video and channel: the source says where the install came from, the campaign which video.
 * Without `utm_source` neither store reads anything, so it must never be left out.
 */
final class TrackedLinkTest extends TestCase
{
    public function test_each_channel_gets_its_own_source_and_the_catalogs_campaign(): void
    {
        $demo = $this->demo();

        $this->assertSame(
            'https://uselisto.com/app?utm_source=tiktok&utm_medium=social&utm_campaign=katalog-konzum-2026-10-07',
            TrackedLink::for($demo, Platform::TikTok)['url'],
        );
        $this->assertSame('instagram', TrackedLink::for($demo, Platform::InstagramBusiness)['source']);
        $this->assertSame('facebook', TrackedLink::for($demo, Platform::FacebookPage)['source']);
        $this->assertSame('katalog-konzum-2026-10-07', TrackedLink::for($demo, Platform::TikTok)['campaign']);
    }

    public function test_the_same_video_as_an_ad_differs_by_medium_only(): void
    {
        $paid = TrackedLink::paid($this->demo(), Platform::TikTok);

        $this->assertSame('https://uselisto.com/app?utm_source=tiktok&utm_medium=paid&utm_campaign=katalog-konzum-2026-10-07', $paid['url']);
        $this->assertSame('paid', $paid['medium']);
        $this->assertSame(TrackedLink::for($this->demo(), Platform::TikTok)['campaign'], $paid['campaign']);
        $this->assertNull(TrackedLink::paid($this->demo(), Platform::FacebookGroup));
    }

    public function test_a_channel_that_is_not_measured_gets_no_tracked_link(): void
    {
        $this->assertNull(TrackedLink::for($this->demo(), Platform::FacebookGroup));
    }

    public function test_no_address_or_campaign_from_the_source_means_no_link_rather_than_a_guess(): void
    {
        $this->assertNull(TrackedLink::for($this->demo(['app_url' => null]), Platform::TikTok));
        $this->assertNull(TrackedLink::for($this->demo(['campaign' => null]), Platform::TikTok));
    }

    public function test_an_address_that_already_has_a_query_keeps_it(): void
    {
        $link = TrackedLink::for($this->demo(['app_url' => 'https://uselisto.com/app?lang=hr']), Platform::TikTok);

        $this->assertStringStartsWith('https://uselisto.com/app?lang=hr&utm_source=tiktok', $link['url']);
    }

    public function test_the_apple_campaign_name_still_holds_the_date_after_the_stores_cut_it_at_40(): void
    {
        // Listo's /app builds Apple's `ct` as "<source>-<campaign>-poveznica" and App Store Connect keeps 40 characters.
        foreach (['konzum', 'kaufland', 'eurospin', 'plodine'] as $chain) {
            foreach (['tiktok', 'instagram', 'facebook'] as $source) {
                $ct = mb_substr("{$source}-katalog-{$chain}-2026-10-07-poveznica", 0, 40);

                $this->assertStringContainsString('2026-10-07', $ct, "{$source} / {$chain}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function demo(array $overrides = []): CatalogDemo
    {
        $payload = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/catalog-feed-konzum-2026-10-07.json'), true, flags: JSON_THROW_ON_ERROR);

        return CatalogDemo::fromArray([...$payload['items'][0]['raw']['demo'], ...$overrides]);
    }
}
