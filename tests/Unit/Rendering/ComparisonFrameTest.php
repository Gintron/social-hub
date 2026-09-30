<?php

declare(strict_types=1);

namespace Tests\Unit\Rendering;

use App\Rendering\ComparisonFrame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComparisonFrameTest extends TestCase
{
    /**
     * @return iterable<string, array{int, float}>
     */
    public static function formats(): iterable
    {
        foreach (['story' => 1920, 'portrait' => 1350, 'square' => 1080] as $name => $height) {
            foreach ([0.8, 1.0, 1.4, 1.5] as $ratio) {
                yield "{$name} {$ratio}" => [$height, $ratio];
            }
        }
    }

    public function test_a_bar_is_as_long_as_its_figure_from_zero_and_the_dearest_is_the_full_track(): void
    {
        $bars = ComparisonFrame::barWidths([
            ['value' => '9,98 €/kg'], ['value' => '11,98 €/kg'], ['value' => '16,23 €/kg'],
        ]);

        // 9,98 / 16,23 is 61 %, under the floor a shop's name and figure need to fit in.
        $this->assertSame([ComparisonFrame::MIN_BAR, 74, 100], $bars);
    }

    public function test_thousands_and_a_unit_do_not_confuse_the_reading_of_the_figure(): void
    {
        $this->assertSame([80, 100], ComparisonFrame::barWidths([['value' => '1.200,00 €'], ['value' => '1.500,00 € / kom']]));
    }

    public function test_a_ranking_with_a_figure_that_is_not_a_plain_number_is_drawn_without_bars(): void
    {
        $this->assertNull(ComparisonFrame::barWidths([['value' => '9,98 €/kg'], ['value' => 'od 11,98 €/kg']]));
        $this->assertNull(ComparisonFrame::barWidths([['value' => '9,98 €/kg'], ['value' => 'nema']]));
        // One row has nothing to be compared to.
        $this->assertNull(ComparisonFrame::barWidths([['value' => '9,98 €/kg']]));
        $this->assertNull(ComparisonFrame::barWidths([]));
    }

    #[DataProvider('formats')]
    public function test_the_picture_leaves_room_for_the_cheapest_price_in_every_format(int $height, float $ratio): void
    {
        $g = ComparisonFrame::layout(1080, $height, ['ratio' => $ratio, 'cut' => false]);

        $free = ($height - $g['padTop'] - $g['padBottom'] - ($g['story'] ? 0 : 120)) / $g['zoom'];

        // The question, the block without its picture, the two runners-up and the badges are 861 px tall.
        if ($g['th'] > 200) {
            $this->assertLessThanOrEqual($free, 861 + $g['th']);
        }
        $this->assertLessThanOrEqual(340, $g['th']);
        $this->assertLessThanOrEqual(290, $g['tw']);
    }

    public function test_the_story_keeps_clear_of_the_apps_interface_and_the_others_are_scaled_to_the_footer(): void
    {
        $story = ComparisonFrame::layout(1080, 1920);
        $portrait = ComparisonFrame::layout(1080, 1350);

        $this->assertSame([1.0, 250, 440], [$story['zoom'], $story['padTop'], $story['padBottom']]);
        $this->assertLessThan(1.0, $portrait['zoom']);
        $this->assertFalse($portrait['story']);
    }
}
