<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * When everything in the new-catalog video happens, in seconds from its start.
 *
 * One list of times is what the picture, the subtitles and the soundtrack all read, so the reaction of the app
 * (the mark, the tick, the toast) falls on the frame where the "added" sound starts, and a sentence never runs
 * into the next scene. The video waits for its words, like the slideshow does: the news is said before the
 * pointer moves, the list stays up until "dodirom na letak" has been said, and the end card is there for
 * "Preuzmi Listo" and where the link is. With a voice that is about 16 seconds; without one the same
 * shape, timed by the length the sentences would take.
 *
 * Every time a frame is drawn at is a multiple of 1/30 s; only the voice starts where the words say.
 */
final class CatalogVideoPlan
{
    public const FPS = 30;

    /** A voice starts a moment after the video does: speech on the very first frame sounds pasted on. */
    public const VOICE_LEAD = 0.15;

    private const SENTENCE_GAP = 0.3;

    /** The title is on the cover at least this long, voice or not. */
    private const MIN_INTRO = 1.6;

    private const SWIPE_SECONDS = 0.42;

    private const SWIPE_STEP = 0.62;

    /** First tap after the last swipe has settled; the pointer needs about a second to come to the card. */
    private const FIRST_TAP_AFTER = 0.75;

    /** Gaps between taps on one page: the reference video's 1.7 s and 1.3 s, a little tighter. */
    private const TAP_GAPS = [1.5, 1.35];

    private const HOLD_BEFORE_TURN = 0.9;

    /** The pointer leaves 0.22 s after the last tap and is gone 0.55 s later. */
    private const POINTER_EXIT = 0.6;

    private const PULL_OUT = 0.45;

    private const LIST_SECONDS = 2.7;

    private const END_CARD_MIN = 3.5;

    private const TAIL = 0.7;

    /** What a sentence would take if nobody has said it: about 14 characters a second. */
    private const SECONDS_PER_CHARACTER = 0.072;

    /**
     * @param  list<float|null>|null  $spoken  Seconds each of the four sentences takes when spoken; null where there is
     *                                         no voice (then they are estimated, so a video without a voice is the same video).
     * @param  list<string>  $lines  The four sentences (CatalogCopy::voiceLines).
     * @return array{
     *     duration: float,
     *     intro: array{end: float},
     *     camera: array{paperAt: float, paperDur: float},
     *     swipes: list<array{at: float, dur: float, from: int, to: int}>,
     *     taps: list<array{at: float, page: int}>,
     *     zoom: array{at: float, dur: float},
     *     nav: array{at: float, pullDur: float},
     *     list: array{at: float, fadeDur: float, settleDur: float, end: float},
     *     cta: array{at: float, dur: float},
     *     titles: array{tapAt: float, listAt: float},
     *     voice: list<array{at: float, seconds: float, text: string}>,
     *     captions: list<array{at: float, until: float, text: string}>,
     *     sounds: list<float>,
     * }
     */
    public static function build(CatalogDemo $demo, array $lines, ?array $spoken = null): array
    {
        $hasVoice = $spoken !== null;
        $seconds = [];

        foreach ($lines as $i => $line) {
            $seconds[$i] = max(0.6, (float) ($spoken[$i] ?? 0) ?: mb_strlen($line) * self::SECONDS_PER_CHARACTER);
        }

        $first = self::VOICE_LEAD;
        $introEnd = self::frame(max(self::MIN_INTRO, $first + $seconds[0] + 0.15));
        $second = $introEnd;

        // The pages are visited in order: the cover, then each page a tap is on. A swipe is one step.
        $pages = array_map(fn (array $page): int => $page['number'], $demo->pages);
        // The first swipe starts under the last word of the first sentence.
        $cursor = $introEnd - 0.05;
        $index = 0;
        $swipes = [];
        $taps = [];
        $gap = 0;

        foreach ($demo->taps as $tap) {
            $target = array_search($tap['page'], $pages, true);
            $target = $target === false ? $index : $target;
            $swiped = false;

            // A page is not turned on the frame of a tap: the mark, the tick and the toast are seen first.
            if ($taps !== [] && $index < $target) {
                $cursor += self::HOLD_BEFORE_TURN;
            }

            while ($index < $target) {
                $swipes[] = ['at' => self::frame($cursor), 'dur' => self::frame(self::SWIPE_SECONDS), 'from' => $pages[$index], 'to' => $pages[$index + 1]];
                $cursor += self::SWIPE_STEP;
                $index++;
                $swiped = true;
            }

            if ($swiped || $taps === []) {
                // The pointer comes to the first card after the page has stopped moving.
                $cursor += ($swiped ? self::FIRST_TAP_AFTER - (self::SWIPE_STEP - self::SWIPE_SECONDS) : self::FIRST_TAP_AFTER);
                $gap = 0;
            } else {
                $cursor += self::TAP_GAPS[min($gap++, count(self::TAP_GAPS) - 1)];
            }

            $taps[] = ['at' => self::frame($cursor), 'page' => $tap['page']];
        }

        $lastTap = $taps[count($taps) - 1]['at'];
        $navAt = $lastTap + self::POINTER_EXIT;
        $listAt = $navAt + self::PULL_OUT;

        // The list does not come up before "…dodirom na letak" has been said.
        $secondEnd = $second + $seconds[1];
        if ($listAt < $secondEnd + 0.35) {
            $listAt = $secondEnd + 0.35;
            $navAt = $listAt - self::PULL_OUT;
        }

        $navAt = self::frame($navAt);
        $listAt = self::frame($listAt);
        $ctaAt = self::frame($listAt + self::LIST_SECONDS);

        $third = $ctaAt + 0.15;
        $fourth = $third + $seconds[2] + self::SENTENCE_GAP;
        $duration = self::frame(max($ctaAt + self::END_CARD_MIN, $fourth + $seconds[3] + self::TAIL), true);

        $starts = [$first, $second, $third, $fourth];
        $voice = [];
        $captions = [];

        foreach ($lines as $i => $text) {
            $voice[] = ['at' => round($starts[$i], 3), 'seconds' => round($seconds[$i], 3), 'text' => $text];
            // The subtitle stays a moment past the last word, so the end of a sentence can be read.
            $captions[] = ['at' => round($starts[$i], 3), 'until' => round($starts[$i] + $seconds[$i] + 0.12, 3), 'text' => $text];
        }

        return [
            'duration' => $duration,
            'intro' => ['end' => $introEnd],
            'camera' => ['paperAt' => self::frame(max(0.2, $introEnd - 0.65)), 'paperDur' => 0.55],
            'swipes' => $swipes,
            'taps' => $taps,
            'zoom' => ['at' => self::frame($taps[0]['at'] + 0.55), 'dur' => 0.45],
            'nav' => ['at' => $navAt, 'pullDur' => self::PULL_OUT],
            'list' => ['at' => $listAt, 'fadeDur' => 0.3, 'settleDur' => 0.55, 'end' => $ctaAt],
            'cta' => ['at' => $ctaAt, 'dur' => 0.4],
            'titles' => ['tapAt' => $introEnd - 0.1, 'listAt' => $listAt],
            'voice' => $hasVoice ? $voice : [],
            'captions' => $captions,
            'sounds' => array_map(fn (array $tap): float => $tap['at'], $taps),
        ];
    }

    /**
     * The nearest frame boundary (or the next one up), so what happens "at" a time is drawn on a frame
     * that is exactly that time — and a sound placed there starts on it.
     */
    public static function frame(float $seconds, bool $up = false): float
    {
        $frames = $up ? ceil($seconds * self::FPS - 1e-9) : round($seconds * self::FPS);

        return round($frames / self::FPS, 5);
    }
}
