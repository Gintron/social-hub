<?php

declare(strict_types=1);

namespace App\Sources;

use App\Enums\SourceType;
use App\Models\Source;
use App\Sources\Contracts\ContentSource;
use App\Sources\Exceptions\SourceException;
use Illuminate\Contracts\Container\Container;

/**
 * Maps a source type to the adapter that reads it.
 */
final class SourceRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(Source $source): ContentSource
    {
        return match ($source->type) {
            SourceType::SocialFeedV1 => $this->container->make(SocialFeedV1Source::class),
            SourceType::Rss => $this->container->make(RssFeedSource::class),
            SourceType::Webhook => throw new SourceException('Webhook sources are pushed to /api/ingest and are never pulled.'),
        };
    }
}
