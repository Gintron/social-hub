<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Enums\Platform;

/**
 * The link a video points to, with the channel it was posted on: `?utm_source=<tiktok|instagram|facebook>`,
 * `utm_medium=social` and the campaign the source named (`katalog-<lanac>-<valid_from>`).
 *
 * One link per video and channel, so an install can be traced to the post that brought it. On Android
 * the store reads it from the Play referrer, on Apple from `ct`; without `utm_source` neither reads
 * anything, which is why the source is never left out. The source's `/app` opens the right store for
 * the phone it is opened on (Listo: server/pages/entryLinks.ts).
 */
final class TrackedLink
{
    public const MEDIUM = 'social';

    /** The same leaflet and channel, bought: told apart from the organic post by the medium, not by the campaign. */
    public const MEDIUM_PAID = 'paid';

    /**
     * @return array{url: string, source: string, medium: string, campaign: string}|null Null when the source
     *                                                                                   gave no address or campaign, or the channel is not one of the three that are measured.
     */
    public static function for(CatalogDemo $demo, Platform $platform): ?array
    {
        return self::build($demo, $platform, self::MEDIUM);
    }

    /**
     * The link of the same video as an ad (`utm_medium=paid`), for the platform's own promotion tool.
     *
     * @return array{url: string, source: string, medium: string, campaign: string}|null
     */
    public static function paid(CatalogDemo $demo, Platform $platform): ?array
    {
        return self::build($demo, $platform, self::MEDIUM_PAID);
    }

    public static function source(Platform $platform): ?string
    {
        return match ($platform) {
            Platform::TikTok => 'tiktok',
            Platform::InstagramBusiness => 'instagram',
            Platform::FacebookPage => 'facebook',
            // A group post is pasted by hand and is not one of the measured channels.
            Platform::FacebookGroup => null,
        };
    }

    /**
     * @return array{url: string, source: string, medium: string, campaign: string}|null
     */
    private static function build(CatalogDemo $demo, Platform $platform, string $medium): ?array
    {
        $source = self::source($platform);

        if ($source === null || $demo->appUrl === null || $demo->campaign === null) {
            return null;
        }

        $query = http_build_query([
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $demo->campaign,
        ], '', '&', PHP_QUERY_RFC3986);

        // The address may already carry a query of its own.
        $url = $demo->appUrl.(str_contains($demo->appUrl, '?') ? '&' : '?').$query;

        return ['url' => $url, 'source' => $source, 'medium' => $medium, 'campaign' => $demo->campaign];
    }
}
