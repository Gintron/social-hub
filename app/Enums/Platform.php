<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Platform: string implements HasColor, HasLabel
{
    case FacebookPage = 'fb_page';
    case InstagramBusiness = 'ig_business';
    case FacebookGroup = 'fb_group';
    case TikTok = 'tiktok';

    public function label(): string
    {
        return match ($this) {
            self::FacebookPage => 'Facebook Page',
            self::InstagramBusiness => 'Instagram',
            self::FacebookGroup => 'Facebook grupa (ručno)',
            self::TikTok => 'TikTok',
        };
    }

    /**
     * Platforms the hub cannot publish to via an official API; a human posts and marks it done.
     */
    public function isManual(): bool
    {
        return $this === self::FacebookGroup;
    }

    public function requiresImage(): bool
    {
        return in_array($this, [self::InstagramBusiness, self::TikTok], true);
    }

    /**
     * What this channel can post, in the order the review screen offers it.
     *
     * @return list<ContentFormat>
     */
    public function formats(): array
    {
        return match ($this) {
            self::FacebookPage => [ContentFormat::Image, ContentFormat::Carousel, ContentFormat::Video, ContentFormat::Link],
            self::InstagramBusiness => [ContentFormat::Image, ContentFormat::Carousel, ContentFormat::Video],
            self::TikTok => [ContentFormat::Video, ContentFormat::Image, ContentFormat::Carousel],
            // A group post is pasted by hand; a video would have to be downloaded and uploaded again.
            self::FacebookGroup => [ContentFormat::Image, ContentFormat::Carousel, ContentFormat::Link],
        };
    }

    public function defaultFormat(): ContentFormat
    {
        return $this === self::TikTok ? ContentFormat::Video : ContentFormat::Image;
    }

    /**
     * Most images one carousel may carry: Instagram and Facebook stop at 10, a TikTok photo post at 35.
     */
    public function maxSlides(): int
    {
        return $this === self::TikTok ? 35 : 10;
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FacebookPage => 'info',
            self::InstagramBusiness => 'danger',
            self::FacebookGroup => 'gray',
            self::TikTok => 'gray',
        };
    }
}
