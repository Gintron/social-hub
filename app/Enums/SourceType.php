<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SourceType: string implements HasLabel
{
    case SocialFeedV1 = 'social_feed_v1';
    case Rss = 'rss';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::SocialFeedV1 => 'Social Feed v1 (pull)',
            self::Rss => 'RSS / Atom / JSON Feed',
            self::Webhook => 'Webhook (push)',
        };
    }

    public function isPull(): bool
    {
        return $this !== self::Webhook;
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
