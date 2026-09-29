<?php

declare(strict_types=1);

namespace Tests\Unit\Rendering;

use App\Rendering\DealFrame;
use App\Rendering\TemplateData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DealFrameTest extends TestCase
{
    /**
     * @return iterable<string, array{int, bool, float}>
     */
    public static function formats(): iterable
    {
        foreach (['story' => 1920, 'portrait' => 1350, 'square' => 1080] as $name => $height) {
            foreach ([false, true] as $card) {
                foreach ([0.8, 1.0, 1.46, 1.6] as $ratio) {
                    yield "{$name} ".($card ? 'card' : 'hook')." {$ratio}" => [$height, $card, $ratio];
                }
            }
        }
    }

    public function test_a_shouted_title_is_set_as_a_title_and_a_mixed_one_is_left_alone(): void
    {
        $this->assertSame('Tre Mulini Noodles Yakisoba Povrće/Pilet', TemplateData::displayTitle('TRE MULINI NOODLES YAKISOBA POVRĆE/PILET'));
        $this->assertSame('Soft Dream VIŠEKRATNI RUČNICI 3 SLOJA', TemplateData::displayTitle('Soft Dream VIŠEKRATNI RUČNICI 3 SLOJA'));
        $this->assertSame('3 Kom', TemplateData::displayTitle('3 KOM'));
        $this->assertSame('123', TemplateData::displayTitle('123'));
        $this->assertSame('', TemplateData::displayTitle('  '));
    }

    public function test_a_price_is_split_into_its_parts_and_other_wording_is_left_whole(): void
    {
        $this->assertSame(['whole' => '13', 'cents' => '19', 'unit' => '€'], TemplateData::priceParts('13,19 €'));
        $this->assertSame(['whole' => '1.319', 'cents' => '00', 'unit' => '€ / kg'], TemplateData::priceParts('1.319,00 € / kg'));
        $this->assertSame(['whole' => '0', 'cents' => '99', 'unit' => '€'], TemplateData::priceParts('0,99'));
        $this->assertNull(TemplateData::priceParts('od 1,99 €'));
        $this->assertNull(TemplateData::priceParts('7.00 - 8.00 €/H'));
        $this->assertNull(TemplateData::priceParts(null));
    }

    public function test_an_ordinary_crop_is_cut_at_the_bottom_and_an_odd_one_is_fitted_whole(): void
    {
        $ordinary = TemplateData::tileShape(640 / 437);
        $this->assertTrue($ordinary['cut']);
        $this->assertEqualsWithDelta((640 / 437) / 0.92, $ordinary['ratio'], 0.001);

        // A tall crop (a leaflet tile of one slim product) and a wide one are never stretched or cut.
        $this->assertSame(['ratio' => 0.8, 'cut' => false], TemplateData::tileShape(295 / 1021));
        $this->assertSame(['ratio' => 1.5, 'cut' => false], TemplateData::tileShape(536 / 160));
        $this->assertTrue(TemplateData::tileShape(null)['cut']);
    }

    #[DataProvider('formats')]
    public function test_the_picture_leaves_room_for_the_price_in_every_format(int $height, bool $card, float $ratio): void
    {
        $g = DealFrame::layout(1080, $height, $card, ['ratio' => $ratio, 'cut' => false]);

        // What is stacked under the picture is fixed: the shop, the block, and on the card two facts.
        $stack = 152 + 30 + $g['th'] + 441 - DealFrame::OVERLAP + ($card ? 140 : 0);
        $free = ($height - $g['padTop'] - $g['padBottom'] - ($g['story'] ? 0 : 120)) / $g['zoom'];

        // A very slim picture may hit the floor; every other one must fit.
        if ($g['th'] > 280) {
            $this->assertLessThanOrEqual($free, $stack);
        }
        $this->assertGreaterThanOrEqual(0, $g['cx']);
        // The stamp hangs off the corner but never off the frame.
        $this->assertLessThanOrEqual($g['width'], $g['cx'] + $g['stampLeft'] + 230);
        $this->assertLessThanOrEqual($g['width'], $g['tw'], 'the picture is never wider than the frame');
    }

    public function test_the_story_keeps_clear_of_the_apps_own_interface_and_the_others_keep_the_footer(): void
    {
        $story = DealFrame::layout(1080, 1920, false, ['ratio' => 1.5, 'cut' => false]);
        $portrait = DealFrame::layout(1080, 1350, false, ['ratio' => 1.5, 'cut' => false]);

        $this->assertTrue($story['story']);
        $this->assertSame([250, 440], [$story['padTop'], $story['padBottom']]);
        $this->assertFalse($portrait['story']);
        $this->assertLessThan(60, $portrait['padTop']);
    }
}
