<?php

declare(strict_types=1);

namespace App\Rendering;

/**
 * Where the parts of a deal's hook and card go, per format, so a template does not do arithmetic in CSS.
 *
 * Both frames stack the same things and centre them in what the format leaves free: the shop, the leaflet
 * picture, then one block with the name and the price (the card adds the two facts under it). On 9:16 the
 * app's own interface covers the top and the bottom, so the free band is the middle of it; a 4:5 or a square
 * keeps the brand's footer and needs no such margins. The picture takes the height that is left, so a long
 * name or two facts never push the price out of the frame.
 */
final class DealFrame
{
    public const OVERLAP = 40;

    /** The shop's plaque, the gap under it, the name-and-price block, and how far that block overlaps the picture. */
    private const PLAQUE = 152;

    private const GAP = 30;

    private const BLOCK = 441;

    /** Two facts under the block on the card. */
    private const CHIPS = 140;

    /** Rendered at this scale on a square, which has no room for the 4:5 sizes. */
    private const SQUARE_ZOOM = 0.8;

    /**
     * @param  array{ratio: float, cut: bool}|null  $shape  TemplateData::tileShape()
     * @return array{story: bool, square: bool, zoom: float, padTop: int, padBottom: int, width: int, tw: int, th: int, cx: int, stampLeft: int}
     */
    public static function layout(int $width, int $height, bool $card, ?array $shape): array
    {
        $story = $height > 1500;
        $square = $height <= 1080;
        $zoom = $square ? self::SQUARE_ZOOM : 1.0;
        $padTop = $story ? 250 : ($square ? 36 : 52);
        $padBottom = $story ? 440 : ($square ? 36 : 52);
        $footer = $story ? 0 : 120;

        $free = ($height - $padTop - $padBottom - $footer) / $zoom;
        $fixed = self::PLAQUE + self::GAP + self::BLOCK - self::OVERLAP + ($card ? self::CHIPS : 0);
        $cap = (int) max(280, min($card ? 500 : 600, floor($free - $fixed)));

        $ratio = $shape['ratio'] ?? 1.3;
        $tw = $card ? 900 : 960;
        $th = (int) round($tw / $ratio);

        if ($th > $cap) {
            $th = $cap;
            $tw = (int) round($th * $ratio);
        }

        $inner = (int) round($width / $zoom);
        $cx = (int) round(($inner - $tw) / 2);

        return [
            'story' => $story,
            'square' => $square,
            'zoom' => $zoom,
            'padTop' => $padTop,
            'padBottom' => $padBottom,
            'width' => $inner,
            'tw' => $tw,
            'th' => $th,
            'cx' => $cx,
            // The discount stamp hangs off the picture's top right corner, but never off the frame.
            'stampLeft' => min($tw - 100, $inner - 230 - $cx),
        ];
    }
}
