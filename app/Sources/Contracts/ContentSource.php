<?php

declare(strict_types=1);

namespace App\Sources\Contracts;

use App\Models\Source;
use App\Sources\ContentItemData;
use App\Sources\SourceTestResult;
use Carbon\CarbonImmutable;
use Generator;

/**
 * An adapter that turns one kind of source into Social Feed items.
 * There is one adapter per *transport* (Social Feed v1 pull, RSS, webhook), never per brand.
 */
interface ContentSource
{
    /**
     * Stream every item changed since `$since` (all items when null), following pagination.
     *
     * @return Generator<int, ContentItemData>
     *
     * @throws \App\Sources\Exceptions\SourceException
     */
    public function fetch(Source $source, ?CarbonImmutable $since): Generator;

    /**
     * Fetch one small page and report problems a human should fix before enabling the source.
     *
     * @throws \App\Sources\Exceptions\SourceException
     */
    public function test(Source $source): SourceTestResult;
}
