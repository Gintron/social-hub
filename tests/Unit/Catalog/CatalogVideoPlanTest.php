<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\CatalogCopy;
use App\Catalog\CatalogDemo;
use App\Catalog\CatalogVideoPlan;
use PHPUnit\Framework\TestCase;

/**
 * The plan is the one list of times the picture, the subtitles and the soundtrack all read. What matters is
 * that nothing in it can drift: taps fall on frames, a sentence never runs into the next scene, and a video
 * with no voice is the same video.
 */
final class CatalogVideoPlanTest extends TestCase
{
    /** What Luka (eleven_v4) took for the four sentences of the Konzum video on 3 Oct 2026. */
    private const SPOKEN = [2.69, 4.05, 1.23, 2.69];

    public function test_with_the_real_voice_the_video_is_about_sixteen_seconds_in_the_owners_order(): void
    {
        $plan = $this->plan(self::SPOKEN);

        $this->assertEqualsWithDelta(16.7, $plan['duration'], 0.6);
        $this->assertLessThan($plan['taps'][0]['at'], $plan['intro']['end']);
        $this->assertLessThan($plan['taps'][1]['at'], $plan['taps'][0]['at']);
        $this->assertLessThan($plan['taps'][2]['at'], $plan['taps'][1]['at']);
        $this->assertLessThan($plan['nav']['at'], $plan['taps'][2]['at']);
        $this->assertLessThan($plan['list']['at'], $plan['nav']['at']);
        $this->assertLessThan($plan['cta']['at'], $plan['list']['at']);
        $this->assertLessThan($plan['duration'], $plan['cta']['at'] + 3.5);
    }

    public function test_every_time_a_frame_is_drawn_at_is_a_whole_frame(): void
    {
        $plan = $this->plan(self::SPOKEN);
        $times = [
            ...array_column($plan['taps'], 'at'), ...array_column($plan['swipes'], 'at'),
            $plan['intro']['end'], $plan['nav']['at'], $plan['list']['at'], $plan['cta']['at'], $plan['duration'],
            $plan['zoom']['at'], $plan['camera']['paperAt'],
        ];

        foreach ($times as $time) {
            $this->assertEqualsWithDelta(0.0, fmod($time * CatalogVideoPlan::FPS, 1.0) > 0.5 ? 1 - fmod($time * 30, 1.0) : fmod($time * 30, 1.0), 1e-3, "{$time} is not on a frame");
        }
    }

    public function test_the_added_sound_is_placed_on_the_frame_of_each_tap(): void
    {
        $plan = $this->plan(self::SPOKEN);

        $this->assertSame(array_column($plan['taps'], 'at'), $plan['sounds']);
        $this->assertCount(3, $plan['sounds']);
    }

    public function test_a_sentence_never_starts_before_the_previous_one_has_ended(): void
    {
        $plan = $this->plan(self::SPOKEN);

        foreach ($plan['voice'] as $i => $voice) {
            if ($i > 0) {
                $this->assertGreaterThanOrEqual($plan['voice'][$i - 1]['at'] + $plan['voice'][$i - 1]['seconds'], $voice['at'], "sentence {$i}");
            }
        }

        // …and the news is said before the pointer moves, the list waits for "dodirom na letak".
        $this->assertGreaterThanOrEqual($plan['voice'][0]['at'] + $plan['voice'][0]['seconds'], $plan['intro']['end']);
        $this->assertGreaterThanOrEqual($plan['voice'][1]['at'] + $plan['voice'][1]['seconds'], $plan['list']['at']);
        // …the call and where the link is are said over the end card, and the video outlasts them.
        $this->assertGreaterThanOrEqual($plan['cta']['at'], $plan['voice'][2]['at']);
        $this->assertGreaterThan($plan['voice'][3]['at'] + $plan['voice'][3]['seconds'], $plan['duration']);
    }

    public function test_a_slow_voice_makes_the_video_wait_for_it(): void
    {
        $fast = $this->plan([1.5, 2.0, 0.8, 1.5]);
        $slow = $this->plan([3.2, 6.5, 1.5, 4.0]);

        $this->assertGreaterThan($fast['duration'], $slow['duration']);
        $this->assertGreaterThan($fast['list']['at'], $slow['list']['at']);
        $this->assertGreaterThanOrEqual($slow['voice'][1]['at'] + 6.5, $slow['list']['at']);
    }

    public function test_without_a_voice_it_is_the_same_video_timed_by_the_length_of_the_sentences(): void
    {
        $voiced = $this->plan(self::SPOKEN);
        $silent = $this->plan(null);

        $this->assertSame([], $silent['voice'], 'nothing to place in the mix');
        $this->assertCount(4, $silent['captions'], 'the sentences are still on the screen as subtitles');
        $this->assertCount(3, $silent['taps']);
        $this->assertEqualsWithDelta($voiced['duration'], $silent['duration'], 2.0);
    }

    public function test_subtitles_follow_the_voice_and_stay_a_moment_past_its_last_word(): void
    {
        $plan = $this->plan(self::SPOKEN);

        foreach ($plan['captions'] as $i => $caption) {
            $this->assertSame($plan['voice'][$i]['at'], $caption['at']);
            $this->assertEqualsWithDelta($plan['voice'][$i]['at'] + $plan['voice'][$i]['seconds'] + 0.12, $caption['until'], 0.001);
        }
    }

    public function test_the_cover_is_swiped_to_the_page_of_the_taps_one_step_at_a_time(): void
    {
        $plan = $this->plan(self::SPOKEN);

        $this->assertSame([['from' => 1, 'to' => 5], ['from' => 5, 'to' => 6]], array_map(fn (array $s): array => ['from' => $s['from'], 'to' => $s['to']], $plan['swipes']));
        $this->assertLessThan($plan['taps'][0]['at'], $plan['swipes'][1]['at'] + $plan['swipes'][1]['dur']);
    }

    public function test_a_demo_on_the_cover_needs_no_swipe(): void
    {
        $raw = $this->raw();
        $raw['pages'] = [$raw['pages'][0]];
        $raw['taps'] = array_map(fn (array $tap): array => [...$tap, 'page' => 1], $raw['taps']);

        $plan = CatalogVideoPlan::build(CatalogDemo::fromArray($raw), CatalogCopy::voiceLines(CatalogDemo::fromArray($raw)), self::SPOKEN);

        $this->assertSame([], $plan['swipes']);
        $this->assertLessThan($this->plan(self::SPOKEN)['duration'], $plan['duration']);
    }

    public function test_taps_on_two_pages_swipe_between_them_after_the_first_taps(): void
    {
        $raw = $this->raw();
        $raw['pages'] = [$raw['pages'][0], $raw['pages'][1], $raw['pages'][2]];
        $raw['taps'][0]['page'] = 5;
        $raw['taps'][1]['page'] = 5;
        $raw['taps'][2]['page'] = 6;
        $demo = CatalogDemo::fromArray($raw);

        $plan = CatalogVideoPlan::build($demo, CatalogCopy::voiceLines($demo), self::SPOKEN);

        $this->assertSame([['from' => 1, 'to' => 5], ['from' => 5, 'to' => 6]], array_map(fn (array $s): array => ['from' => $s['from'], 'to' => $s['to']], $plan['swipes']));
        $this->assertLessThan($plan['taps'][0]['at'], $plan['swipes'][0]['at'] + $plan['swipes'][0]['dur'], 'the page of the first two taps is there before they happen');
        $this->assertGreaterThan($plan['taps'][1]['at'], $plan['swipes'][1]['at'], 'the page turns between the second and the third tap');
        $this->assertLessThan($plan['taps'][2]['at'], $plan['swipes'][1]['at'] + $plan['swipes'][1]['dur']);
    }

    /**
     * @param  list<float>|null  $spoken
     * @return array<string, mixed>
     */
    private function plan(?array $spoken): array
    {
        $demo = CatalogDemo::fromArray($this->raw());

        return CatalogVideoPlan::build($demo, CatalogCopy::voiceLines($demo), $spoken);
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        $payload = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/catalog-feed-konzum-2026-10-07.json'), true, flags: JSON_THROW_ON_ERROR);

        return $payload['items'][0]['raw']['demo'];
    }
}
