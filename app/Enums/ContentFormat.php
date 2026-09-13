<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * What a variant posts, chosen per channel (`settings.format`). Which formats a channel offers is
 * `Platform::formats()`; the media the variant carries has to match (`App\Publishing\FormatCheck`).
 */
enum ContentFormat: string
{
    case Image = 'image';
    case Carousel = 'carousel';
    case Video = 'video';
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Slika',
            self::Carousel => 'Carousel',
            self::Video => 'Video',
            self::Link => 'Link',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Image => Heroicon::OutlinedPhoto,
            self::Carousel => Heroicon::OutlinedRectangleStack,
            self::Video => Heroicon::OutlinedVideoCamera,
            self::Link => Heroicon::OutlinedLink,
        };
    }

    public function needsMedia(): bool
    {
        return $this !== self::Link;
    }
}
