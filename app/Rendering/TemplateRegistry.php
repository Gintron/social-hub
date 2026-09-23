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

    /**
     * The slides one item is told with as a carousel or a video (config/template_sets.php), as
     * template keys in this orientation. Names without a template in that orientation are skipped.
     *
     * @return list<string>
     */
    public function slidesFor(ContentKind $kind, string $orientation): array
    {
        $names = config("template_sets.{$kind->value}") ?? config('template_sets.default', []);
        $keys = [];

        foreach ((array) $names as $name) {
            $key = "kinds/{$name}-{$orientation}";

            if (is_array(config("templates.{$key}"))) {
                $keys[] = $key;
            }
        }

        return $keys !== [] ? $keys : [$this->defaultFor($kind, $orientation)];
    }

    /**
     * The brand's end card in this orientation, if one is configured.
     */
    public function closingFor(string $orientation): ?string
    {
        $key = "kinds/cta-{$orientation}";

        return is_array(config("templates.{$key}")) ? $key : null;
    }

    /**
     * Is this the brand's end card, in whichever orientation it was rendered?
     */
    public function isClosing(?string $key): bool
    {
        return $key !== null && str_starts_with($key, 'kinds/cta-') && is_array(config("templates.{$key}"));
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
