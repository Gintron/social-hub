<?php

declare(strict_types=1);

namespace App\Rendering;

/**
 * Where the parts of a comparison's hook and card go, per format, so a template does no arithmetic in CSS.
 *
 * Like the deal frames, both are centred in what the format leaves free. On 9:16 the app's own interface
 * covers the top and the bottom; a 4:5 or a square keeps the brand's footer. Instead of a second design
 * for those, the same one is scaled (`zoom`), so a long question or five shops never push the cheapest
 * price out of the frame; the leaflet picture takes the height that is left.
 */
final class ComparisonFrame
{
    /** The bar of the dearest shop is the full track; no bar is drawn shorter than this, or the shop and the figure would not fit in it. */
    public const MIN_BAR = 64;

    /** Height of the hook without its picture: the question, the winner's block, the two runners-up, the badges and the gaps. */
    private const HOOK_STACK = 861;

    /**
     * @param  array{ratio: float, cut: bool}|null  $shape  TemplateData::tileShape()
     * @return array{story: bool, square: bool, zoom: float, padTop: int, padBottom: int, tw: int, th: int}
     */
    public static function layout(int $width, int $height, ?array $shape = null): array
    {
        $story = $height > 1500;
        $square = $height <= 1080;
        $zoom = $story ? 1.0 : ($square ? 0.72 : 0.9);
        $padTop = $story ? 250 : ($square ? 36 : 52);
        $padBottom = $story ? 440 : ($square ? 36 : 52);
        $footer = $story ? 0 : 120;

        $free = ($height - $padTop - $padBottom - $footer) / $zoom;
        $cap = (int) max(200, min(340, floor($free - self::HOOK_STACK)));

        $ratio = $shape['ratio'] ?? 1.3;
        $tw = 290;
        $th = (int) round($tw / $ratio);

        if ($th > $cap) {
            $th = $cap;
            $tw = (int) round($th * $ratio);
        }

        return compact('story', 'square', 'zoom', 'padTop', 'padBottom', 'tw', 'th');
    }

    /**
     * How long each row's bar is, in percent of its track: the figure the rows are ranked by, from zero, so a
     * shop twice as dear has a bar twice as long. Null when any figure is not a plain number ("od 9,98 €/kg",
     * "n/a"): then no bar is honest, and the rows are drawn without.
     *
     * @param  list<array{value: string}>  $rows
     * @return list<int>|null
     */
    public static function barWidths(array $rows): ?array
    {
        if (count($rows) < 2) {
            return null;
        }

        $numbers = [];

        foreach ($rows as $row) {
            if (preg_match('/^(\d[\d.]*),(\d{2})(?!\d)/u', mb_trim((string) $row['value']), $m) !== 1) {
                return null;
            }

            $numbers[] = (float) (str_replace('.', '', $m[1]).'.'.$m[2]);
        }

        $max = max($numbers);

        if ($max <= 0) {
            return null;
        }

        return array_map(fn (float $number): int => (int) max(self::MIN_BAR, round($number / $max * 100)), $numbers);
    }
}
