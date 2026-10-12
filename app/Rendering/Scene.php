<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Enums\ContentKind;
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

    /**
     * How long this slide has to stay up to be read, when that is more than the default hold: a quote
     * is its whole text, and three seconds is long enough for a headline, not a sentence. Null leaves
     * the hold to the renderer, which is what every other slide gets.
     */
    public function readingSeconds(): ?float
    {
        if ($this->role !== self::CARD || $this->item?->kind !== ContentKind::Quote) {
            return null;
        }

        $text = mb_trim((string) $this->item->title);
        $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        // A second to take the card in, then about three words a second. Words are what a reader of most
        // languages counts and characters what a text written without spaces is made of, so whichever
        // says more. A slide is held at most MAX_SLIDE_SECONDS, so a text of more than ~27 words is read
        // faster than that: the source keeps what it sends for a video short, the hub does not cut it.
        $reading = max($words / 3, mb_strlen($text) / 20);

        return min(VideoRenderer::MAX_SLIDE_SECONDS, max(4.0, 1.0 + $reading));
    }
}
