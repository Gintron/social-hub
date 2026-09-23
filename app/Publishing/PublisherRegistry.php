<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\Platform;
use App\Publishing\Contracts\Publisher;
use App\Publishing\Exceptions\PermanentPublishException;
use App\Publishing\Meta\FacebookPagePublisher;
use App\Publishing\Meta\InstagramPublisher;
use App\Publishing\TikTok\Business\TikTokBusinessPublisher;
use App\Publishing\TikTok\TikTokPublisher;
use Illuminate\Contracts\Container\Container;

final class PublisherRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(Platform $platform): Publisher
    {
        return match ($platform) {
            Platform::FacebookPage => $this->container->make(FacebookPagePublisher::class),
            Platform::InstagramBusiness => $this->container->make(InstagramPublisher::class),
            Platform::TikTok => $this->container->make(
                config('tiktok.driver') === 'business' ? TikTokBusinessPublisher::class : TikTokPublisher::class,
            ),
            Platform::FacebookGroup => throw new PermanentPublishException('Facebook groups are posted manually; there is no API.', 'manual_only'),
        };
    }
}
