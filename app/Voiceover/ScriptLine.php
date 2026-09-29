<?php

declare(strict_types=1);

namespace App\Voiceover;

/**
 * What is said over one slide, as written: digits and symbols, before SpokenCroatian spells them out.
 * An empty text is a slide left to the music.
 */
final readonly class ScriptLine
{
    public function __construct(
        /** The slide's role (App\Rendering\Scene::HOOK …): what the line is for. */
        public string $role,
        public string $text,
    ) {}
}
