<?php

declare(strict_types=1);

namespace Tests\Unit\Recipe;

use App\Recipe\RecipeInfo;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * `raw.recipe` is read strictly: what a post prints about a dish is what is here, and a block that does not add up is not a post.
 */
final class RecipeInfoTest extends TestCase
{
    public function test_a_block_that_holds_up_is_read_as_it_is(): void
    {
        $info = RecipeInfo::fromArray($this->block(['highlights' => ['Brokula', ' Celer ', 'Brokula', '']]));

        $this->assertSame(4, $info->ingredientsOnSale);
        $this->assertSame(896, $info->totalCents);
        $this->assertSame('2026-11-17', $info->validTo);
        $this->assertSame(['Brokula', 'Celer'], $info->highlights);
        $this->assertSame('8,96 €', RecipeInfo::money($info->totalCents));
        $this->assertSame('1.234,50 €', RecipeInfo::money(123450));
    }

    public function test_every_reason_a_block_is_not_a_post_is_named(): void
    {
        $problems = RecipeInfo::problems($this->block([
            'recipe_id' => '', 'minutes' => 0, 'servings' => '4', 'ingredients_total' => 3, 'ingredients_on_sale' => 5, 'total_cents' => -1, 'valid_to' => '17.11.2026.',
        ]));

        $text = implode(' ', $problems);

        foreach (['recipe_id', 'minutes', 'servings', 'total_cents', 'valid_to', 'ingredients_on_sale ne može biti veći'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_fewer_than_two_ingredients_on_sale_is_not_a_dish_from_the_offers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ingredients_on_sale');

        RecipeInfo::fromArray($this->block(['ingredients_on_sale' => 1]));
    }

    public function test_a_saving_several_times_the_sum_is_a_mistake_in_the_source(): void
    {
        $this->assertSame([], RecipeInfo::problems($this->block(['total_cents' => 500, 'saving_cents' => 1500])));
        $this->assertNotSame([], RecipeInfo::problems($this->block(['total_cents' => 500, 'saving_cents' => 2500])));
    }

    public function test_a_saving_is_shown_only_when_it_is_worth_a_euro(): void
    {
        $this->assertSame(220, RecipeInfo::fromArray($this->block(['saving_cents' => 220]))->shownSavingCents());
        $this->assertSame(100, RecipeInfo::fromArray($this->block(['saving_cents' => 100]))->shownSavingCents());
        $this->assertNull(RecipeInfo::fromArray($this->block(['saving_cents' => 99]))->shownSavingCents());
        $this->assertNull(RecipeInfo::fromArray($this->block(['saving_cents' => 0]))->shownSavingCents());
        $this->assertNull(RecipeInfo::fromArray(array_diff_key($this->block(), ['saving_cents' => 1]))->shownSavingCents());
    }

    public function test_the_cover_of_the_leaflet_is_a_decoration_and_never_a_condition(): void
    {
        $cover = ['url' => 'https://api.uselisto.com/files/catalogs/x/pages/001.webp', 'width' => 1600, 'height' => 2209];

        $this->assertSame($cover, RecipeInfo::fromArray($this->block(['leaflet_cover' => $cover]))->leafletCover);
        $this->assertNull(RecipeInfo::fromArray($this->block())->leafletCover);

        // One that cannot be shown is no cover, and the dish is still a dish.
        foreach ([['url' => '/files/x.webp', 'width' => 10, 'height' => 10], ['url' => 'https://x/y', 'width' => 0, 'height' => 10], ['url' => 'https://x/y', 'width' => '10', 'height' => 10], 'x', []] as $bad) {
            $info = RecipeInfo::fromArray($this->block(['leaflet_cover' => $bad]));

            $this->assertNull($info->leafletCover);
            $this->assertSame(4, $info->ingredientsOnSale);
        }
    }

    public function test_a_source_that_does_not_say_who_drew_the_picture_is_taken_to_have_had_it_drawn(): void
    {
        $this->assertTrue(RecipeInfo::fromArray(array_diff_key($this->block(), ['ai_image' => 1]))->aiImage);
        $this->assertFalse(RecipeInfo::fromArray($this->block(['ai_image' => false]))->aiImage);
    }

    public function test_the_shops_are_written_as_a_screen_has_them_and_as_a_voice_says_them(): void
    {
        $info = RecipeInfo::fromArray($this->block(['chains' => ['Billa Plus (Wien)', 'Lidl Österreich', 'Billa Plus (Wien)']]));

        // A country after the name goes; a regional edition stays on a screen, because a price in Vienna is not one in Graz.
        $this->assertSame(['Billa Plus (Wien)', 'Lidl'], $info->shops());
        $this->assertSame(['Billa Plus', 'Lidl'], $info->shops(spoken: true));

        // The country goes before an edition as well as at the end ("Interspar Österreich (Wien)").
        $regional = RecipeInfo::fromArray($this->block(['chains' => ['Interspar Österreich (Wien)']]));
        $this->assertSame(['Interspar (Wien)'], $regional->shops());
        $this->assertSame(['Interspar'], $regional->shops(spoken: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function block(array $over = []): array
    {
        return [
            'recipe_id' => 'c7bd988657', 'minutes' => 25, 'servings' => 4,
            'ingredients_total' => 7, 'ingredients_on_sale' => 4, 'total_cents' => 896, 'saving_cents' => 220,
            'currency' => 'EUR', 'valid_to' => '2026-11-17', 'chain' => 'ribola', 'chains' => ['Ribola'], 'ai_image' => true,
            'page_url' => 'https://uselisto.com/recept/jelo-c7bd988657', 'app_url' => 'https://uselisto.com/app', 'campaign' => 'recept-2026-W46',
            ...$over,
        ];
    }
}
