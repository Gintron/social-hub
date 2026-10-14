<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * What stands in for a picture that is not there. A list with blanks where thumbnails should be looks
 * unfinished, and a clickable row's empty cell is a link with no name for a screen reader.
 */
final class Placeholders
{
    /**
     * A grey tile with a picture on it, as a data URI: nothing to publish, nothing that can 404.
     */
    public static function thumbnail(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">'
            .'<rect width="48" height="48" rx="8" fill="#f4f4f5"/>'
            .'<path d="M13 31l7-8 5 6 4-4 6 6M17 17.5h.01" fill="none" stroke="#71717a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
            .'</svg>';

        return 'data:image/svg+xml;utf8,'.rawurlencode($svg);
    }
}
