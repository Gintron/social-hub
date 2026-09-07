<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\Brand;
use App\Models\ContentItem;

/**
 * Writes the post text for one content item.
 *
 * An interface with two implementations so tests never call a paid API and so the model behind it
 * can be swapped without touching the agent.
 */
interface CaptionWriter
{
    /**
     * @param  list<string>  $violations  Rules the previous attempt broke, when this is a retry.
     *
     * @throws CaptionWriterException
     */
    public function write(ContentItem $item, Brand $brand, array $violations = []): CaptionResult;
}
