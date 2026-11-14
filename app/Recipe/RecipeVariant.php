<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Catalog\CatalogCopy;
use App\Catalog\TrackedLink;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;

/**
 * What a channel of a recipe draft carries that no other kind does: the link of this dish on this channel, where the post says
 * that link is, the first comment that holds it, and which first second the dish was told with.
 *
 * Same rules as a leaflet's channel (App\Catalog\CatalogVariant), because they are the channel's, not the leaflet's: a Facebook
 * Page keeps the link out of the text and in the first comment (and tracks it), Instagram and TikTok comments cannot be tapped,
 * so they say the channel's own short address (`catalog_video.link_typed`), and a YouTube Short has its link in the description.
 * The link is the dish's page: it is the answer to the post, and it carries the app's store buttons.
 */
final class RecipeVariant
{
    /**
     * @return array{link_url: string|null, settings: array<string, mixed>}
     */
    public static function fields(ContentItem $item, Platform $platform, Brand $brand): array
    {
        if ($item->kind !== ContentKind::Recipe || ($info = RecipeInfo::tryFromItem($item)) === null) {
            return ['link_url' => null, 'settings' => []];
        }

        $where = CatalogCopy::where($platform);
        // Which first second the dish was told with, so the reach of the question and of the number can be compared afterwards.
        $settings = ['link_in' => $where, 'hook' => RecipeCopy::hookStyle($item, $brand)];
        $page = $info->pageUrl ?? $item->url;

        if ($where === CatalogCopy::WHERE_COMMENT && $info->appUrl !== null && (bool) config("catalog_video.link_typed.{$platform->value}", false)) {
            $path = CatalogCopy::typedPath($platform, $brand);
            $address = CatalogCopy::typeable($info->appUrl, $path);
            $settings['first_comment'] = RecipeCopy::comment($address, $brand);

            return ['link_url' => $path !== null ? 'https://'.$address : $info->appUrl, 'settings' => $settings];
        }

        $tracked = self::tracked($info, $platform, $page);

        if ($tracked !== null) {
            $settings['tracking'] = $tracked;

            if ($where === CatalogCopy::WHERE_COMMENT && in_array($platform, [Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok], true)) {
                $settings['first_comment'] = RecipeCopy::comment($tracked['url'], $brand);
            }
        }

        return ['link_url' => $tracked['url'] ?? $page, 'settings' => $settings];
    }

    /**
     * The dish's page with the channel on it (`utm_source`), for a channel that is measured.
     *
     * @return array{url: string, source: string, medium: string, campaign: string}|null
     */
    public static function tracked(RecipeInfo $info, Platform $platform, ?string $page = null): ?array
    {
        return TrackedLink::forUrl($page ?? $info->pageUrl, $info->campaign, $platform);
    }
}
