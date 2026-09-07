<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Enums\ContentKind;
use InvalidArgumentException;

final class TemplateRegistry
{
    /**
     * @return array{view: string, width: int, height: int, kinds: list<string>, label: string}
     */
    public function get(string $key): array
    {
        $template = config("templates.{$key}");

        if (! is_array($template)) {
            throw new InvalidArgumentException("Unknown image template '{$key}'.");
        }

        return $template;
    }

    /**
     * @return array<string, array{view: string, width: int, height: int, kinds: list<string>, label: string}>
     */
    public function all(): array
    {
        return (array) config('templates', []);
    }

    /**
     * @return array<string, string> key => label
     */
    public function optionsFor(?ContentKind $kind = null): array
    {
        $options = [];

        foreach ($this->all() as $key => $template) {
            if ($kind === null || in_array($kind->value, $template['kinds'], true)) {
                $options[$key] = $template['label'];
            }
        }

        return $options;
    }

    /**
     * Vertical (9:16) key for a kind, used for Reels and TikTok slides.
     */
    public function storyFor(ContentKind $kind): string
    {
        return $this->defaultFor($kind, 'story');
    }

    public function defaultFor(ContentKind $kind, string $orientation = 'square'): string
    {
        foreach ($this->all() as $key => $template) {
            if (in_array($kind->value, $template['kinds'], true) && str_ends_with($key, '-'.$orientation)) {
                return $key;
            }
        }

        return "kinds/generic-{$orientation}";
    }
}
