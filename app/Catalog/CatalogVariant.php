<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use InvalidArgumentException;

/**
 * What a channel of a new-catalog draft carries that no other kind does: the link of this video on this channel,
 * where the video says that link is, and the first comment that holds it.
 *
 * The link is stored on the variant (`link_url`, `settings.tracking`) so a result can be attributed to the post
 * that brought it: the campaign is per catalog, the source per channel. The comment is left by the publisher on
 * Facebook and Instagram and, on TikTok, by LeaveTikTokCommentJob (docs/catalog-video.md).
 */
final class CatalogVariant
{
    /**
     * The format a channel posts for a catalog: the video where the channel takes one, else a plain link post.
     */
    public static function format(Platform $platform): ContentFormat
    {
        return in_array(ContentFormat::Video, $platform->formats(), true) ? ContentFormat::Video : ContentFormat::Link;
    }

    /**
     * @return array{link_url: string|null, settings: array<string, mixed>}
     */
    public static function fields(ContentItem $item, Platform $platform, Brand $brand): array
    {
        if ($item->kind !== ContentKind::Catalog) {
            return ['link_url' => null, 'settings' => []];
        }

        try {
            $demo = CatalogDemo::fromItem($item);
        } catch (InvalidArgumentException) {
            // A block that does not hold up cannot be rendered either; the draft says so there, not here.
            return ['link_url' => null, 'settings' => []];
        }

        $where = config("catalog_video.link_in.{$platform->value}") === CatalogCopy::WHERE_BIO ? CatalogCopy::WHERE_BIO : CatalogCopy::WHERE_COMMENT;
        $tracked = TrackedLink::for($demo, $platform);
        $settings = ['link_in' => $where];

        if ($tracked !== null) {
            $settings['tracking'] = $tracked;

            // The link is the first comment: on Facebook it goes below the post because a post with an outside link is shown
            // to fewer people, on Instagram and TikTok because that is what the video says. TikTok's comes from a job of
            // its own (LeaveTikTokCommentJob) once the post is public, and from an admin's hand where the API cannot.
            if ($where === CatalogCopy::WHERE_COMMENT && in_array($platform, [Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok], true)) {
                $settings['first_comment'] = CatalogCopy::comment($demo, $tracked['url'], $brand);
            }
        }

        return ['link_url' => $tracked['url'] ?? $item->url, 'settings' => $settings];
    }
}
