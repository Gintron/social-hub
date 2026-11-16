<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Catalog\CatalogVideoPlan;

/**
 * When everything in the video of a dish happens (resources/recipe-video), in seconds from its start.
 *
 * One list of times is what the picture, the subtitles and the soundtrack read, as in the new-catalog video
 * (CatalogVideoPlan, whose frame rate and rounding this uses). The video waits for its three sentences (RecipeCopy::voiceLines):
 * the hook is said over the dishes, the card's sentence over the app's screen, and the end card is there for where the link is.
 * Without a voice it is the same shape, timed by the length the sentences would take.
 */
final class RecipeVideoPlan
{
    /** The dishes of the hook change this far apart: long enough to see a plate, short enough that the third is there before the hook ends. */
    private const SWAP_STEP = 0.55;

    private const MIN_HOOK = 1.6;

    /** The last dish of the hook (the one the post is about) is seen at least this long before the screen changes. */
    private const LAST_DISH = 0.75;

    private const END_CARD_MIN = 2.6;

    private const TAIL = 0.6;

    /** What a sentence would take if nobody has said it: about 14 characters a second (as CatalogVideoPlan). */
    private const SECONDS_PER_CHARACTER = 0.072;

    /**
     * @param  list<string>  $lines  The hook, the card and the closing (RecipeCopy::voiceLines, in that order).
     * @param  list<float|null>|null  $spoken  Seconds each takes when spoken; null where there is no voice.
     * @param  int  $dishes  How many dishes the hook shows (1 to 3; the dish of the post is the last).
     * @param  int  $rows  Rows of the shopping list (the dish's main ingredients); 0 leaves the list out.
     * @return array{
     *     duration: float,
     *     swaps: list<float>,
     *     hookEnd: float,
     *     phoneAt: float,
     *     scrollAt: float,
     *     pulses: list<float>,
     *     tapAt: float,
     *     listAt: float|null,
     *     rowsAt: list<float>,
     *     ctaAt: float,
     *     voice: list<array{at: float, seconds: float, text: string}>,
     *     captions: list<array{at: float, until: float, text: string}>,
     *     sounds: list<float>,
     * }
     */
    public static function build(array $lines, ?array $spoken = null, int $dishes = 1, int $rows = 0): array
    {
        $lines = array_values($lines);
        $seconds = [];

        foreach ($lines as $i => $line) {
            $seconds[$i] = max(0.6, (float) ($spoken[$i] ?? 0) ?: mb_strlen($line) * self::SECONDS_PER_CHARACTER);
        }

        $dishes = max(1, min(3, $dishes));
        $swaps = array_map(fn (int $i): float => self::frame($i * self::SWAP_STEP), range(0, $dishes - 1));

        $first = CatalogVideoPlan::VOICE_LEAD;
        $hookEnd = self::frame(max(self::MIN_HOOK, $first + $seconds[0] + 0.2, $swaps[$dishes - 1] + self::LAST_DISH));

        $phoneAt = self::frame($hookEnd + 0.1);
        $scrollAt = self::frame($phoneAt + 0.65);
        $second = $phoneAt + 0.35;

        // What the sentence of the card names is shown as it is named: the ingredients on sale, the saving, the sum. Never before the
        // screen has stopped moving, and never closer than half a second.
        $pulses = [];
        $earliest = $scrollAt + 0.65;

        foreach ([0.3, 0.58, 0.84] as $share) {
            $at = max($earliest, $second + $seconds[1] * $share);
            $pulses[] = self::frame($at);
            $earliest = $at + 0.5;
        }

        $tapAt = self::frame(max($second + $seconds[1] + 0.25, $pulses[2] + 0.7));

        if ($rows > 0) {
            $listAt = self::frame($tapAt + 0.3);
            $rowsAt = array_map(fn (int $i): float => self::frame($listAt + 0.3 + $i * 0.16), range(0, $rows - 1));
            $third = $listAt + 0.25;
            // The list is up for the first half of the closing ("Recept i popis su u aplikaciji…"), the end card for where the link is.
            $ctaAt = self::frame(max($third + $seconds[2] * 0.5, $rowsAt[$rows - 1] + 1.1));
        } else {
            $listAt = null;
            $rowsAt = [];
            $ctaAt = self::frame($tapAt + 0.35);
            $third = $ctaAt + 0.15;
        }

        $duration = self::frame(max($ctaAt + self::END_CARD_MIN, $third + $seconds[2] + self::TAIL), true);

        $starts = [$first, $second, $third];
        $voice = [];
        $captions = [];

        foreach ($lines as $i => $text) {
            $voice[] = ['at' => round($starts[$i], 3), 'seconds' => round($seconds[$i], 3), 'text' => $text];
            $captions[] = ['at' => round($starts[$i], 3), 'until' => round($starts[$i] + $seconds[$i] + 0.12, 3), 'text' => $text];
        }

        return [
            'duration' => $duration,
            'swaps' => $swaps,
            'hookEnd' => $hookEnd,
            'phoneAt' => $phoneAt,
            'scrollAt' => $scrollAt,
            'pulses' => $pulses,
            'tapAt' => $tapAt,
            'listAt' => $listAt,
            'rowsAt' => $rowsAt,
            'ctaAt' => $ctaAt,
            'voice' => $spoken !== null ? $voice : [],
            'captions' => $captions,
            // The app's "added" sound, on the frame of the one tap.
            'sounds' => [$tapAt],
        ];
    }

    private static function frame(float $seconds, bool $up = false): float
    {
        return CatalogVideoPlan::frame($seconds, $up);
    }
}
