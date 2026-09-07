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
