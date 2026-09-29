<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Models\ContentItem;

/**
 * One slide of a video before it is rendered: which template, what it is for, and whose it is.
 *
 * The video is planned as scenes first (ScenePlanner) so that what is *said* over it can be written
 * from the same list of slides that is *shown* — the script and the picture cannot drift apart when
 * both come from one plan.
 */
final readonly class Scene
{
    public const COVER = 'cover';

    public const HOOK = 'hook';

    public const CARD = 'card';

    public const CLOSING = 'closing';

    public function __construct(
        public string $templateKey,
        public string $role,
        /** The item the slide is about; null for a roundup's cover and end card, which speak for the whole draft. */
        public ?ContentItem $item = null,
    ) {}
}
