<?php

declare(strict_types=1);

/*
 * Slides for one item posted as a carousel or a video, per content kind, in order. Names map to
 * `kinds/{name}-{orientation}` in config/templates.php. The same set goes into a TikTok photo post,
 * an Instagram carousel and a Reel, so every format tells the item the same way: the hook, the
 * item's own card, then the brand's call to action.
 */
return [
    'job' => ['hook', 'job', 'cta'],
    'deal' => ['hook', 'deal', 'cta'],
    'comparison' => ['comparison-hook', 'comparison', 'cta'],
    'default' => ['generic', 'cta'],
];
