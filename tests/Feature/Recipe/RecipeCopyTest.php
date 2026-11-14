<?php

declare(strict_types=1);

namespace Tests\Feature\Recipe;

use App\Ai\CaptionValidator;
use App\Drafting\CaptionBuilder;
use App\Enums\Platform;
use App\Recipe\RecipeCopy;
use App\Voiceover\SpokenCroatian;
use App\Voiceover\SpokenGerman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesRecipeItems;
use Tests\TestCase;

/**
 * Every word the hub adds to a dish, in both languages, and what it holds itself to: the numbers are the item's, nothing is said
 * about a product, the picture is said to be drawn, and where the link is is said as the channel has it.
 */
final class RecipeCopyTest extends TestCase
{
    use MakesRecipeItems;
    use RefreshDatabase;

    public function test_the_voice_says_the_hook_the_dish_and_the_end_in_croatian(): void
    {
        $item = $this->recipeItem(itemAttributes: []);
        $lines = RecipeCopy::voiceLines($item, $item->brand);

        // Short on purpose: the title, the sum and the saving are on the screen, and a voice that reads them holds a still card for ten seconds.
        $this->assertSame('4 od 7 sastojaka je na akciji u trgovini Ribola.', $lines['card']);
        $this->assertSame('Recept i popis su u aplikaciji Listo. Poveznica je u komentaru.', $lines['closing']);
        $this->assertContains($lines['hook'], ['Što kuhati ovaj tjedan?', '4 sastojka na akciji.', 'Iz letka na tanjur.']);
    }

    public function test_the_voice_says_the_same_in_german_with_the_shop_after_bei(): void
    {
        $item = $this->recipeItem(market: 'at');
        $lines = RecipeCopy::voiceLines($item, $item->brand);

        $this->assertSame('4 von 6 Zutaten sind bei Eurospar im Angebot.', $lines['card']);
        $this->assertSame('Rezept und Einkaufsliste findest du in der App Listo. Den Link findest du im Kommentar.', $lines['closing']);
        $this->assertContains($lines['hook'], ['Was kochen diese Woche?', '4 Zutaten im Angebot.', 'Aus dem Prospekt auf den Teller.']);
    }

    public function test_a_channel_that_has_its_link_in_the_description_says_so(): void
    {
        $item = $this->recipeItem();

        $this->assertStringEndsWith('Poveznica je u opisu.', RecipeCopy::voiceLines($item, $item->brand, 'description')['closing']);
        $this->assertStringEndsWith('Poveznica je u biografiji.', RecipeCopy::voiceLines($item, $item->brand, 'bio')['closing']);
    }

    public function test_the_hook_is_the_brands_choice_and_alternates_by_the_dish_not_by_the_day(): void
    {
        $item = $this->recipeItem();
        $brand = $item->brand;

        foreach (['question' => 'question', 'figure' => 'figure'] as $choice => $expected) {
            $brand->voice = [...$brand->voice, 'recipe_hook' => $choice];
            $this->assertSame($expected, RecipeCopy::hookStyle($item, $brand));
        }

        // Quick enough to be told by its time (25 min) when the brand asks for it; a long dish falls back to the question.
        $brand->voice = [...$brand->voice, 'recipe_hook' => 'time'];
        $this->assertSame('time', RecipeCopy::hookStyle($item, $brand));
        $slow = $this->recipeItem(feedItem: [...$this->recipeFeedItem(1), 'id' => 'recipe:slow', 'raw' => ['recipe' => [...$this->recipeFeedItem(1)['raw']['recipe'], 'minutes' => 75]]], brand: $brand);
        $this->assertSame('question', RecipeCopy::hookStyle($slow, $brand));

        // The leaflet is the brand's choice too, and a dish whose source sent no cover is told with the question instead.
        $brand->voice = [...$brand->voice, 'recipe_hook' => 'leaflet'];
        $this->assertSame('leaflet', RecipeCopy::hookStyle($item, $brand));
        $feed = $this->recipeFeedItem(1);
        unset($feed['raw']['recipe']['leaflet_cover']);
        $plain = $this->recipeItem(feedItem: [...$feed, 'id' => 'recipe:no-cover'], brand: $brand);
        $this->assertSame('question', RecipeCopy::hookStyle($plain, $brand));

        // By turns: the same dish says the same thing every time it is asked, and the five dishes of the fixture do not all say one thing.
        // Without a cover the turns are the question and the number only.
        $brand->voice = [...$brand->voice, 'recipe_hook' => 'alternate'];
        $this->assertContains(RecipeCopy::hookStyle($plain, $brand), ['question', 'figure']);
        $this->assertSame(RecipeCopy::hookStyle($item, $brand), RecipeCopy::hookStyle($item, $brand));
        $styles = array_map(
            fn (int $index): string => RecipeCopy::hookStyle($this->recipeItem(feedItem: [...$this->recipeFeedItem($index), 'id' => "recipe:turn-{$index}"], brand: $brand), $brand),
            range(0, 4),
        );
        $this->assertGreaterThanOrEqual(2, count(array_unique($styles)), 'the dishes do not all say one thing');
        $this->assertEmpty(array_diff($styles, ['question', 'figure', 'leaflet']));
    }

    public function test_the_view_of_a_slide_has_the_numbers_as_the_item_gave_them_and_the_label_of_the_picture(): void
    {
        $item = $this->recipeItem();
        $view = RecipeCopy::view($item, $item->brand);

        $this->assertSame('Ribola', $view['chain']);
        $this->assertSame('8,96 €', $view['sum']);
        $this->assertSame('2,20 €', $view['saving']);
        $this->assertSame('ušteda 2,20 €', $view['hook_saving']);
        $this->assertSame('4 sastojka na akciji', $view['hook_on_sale']);
        $this->assertSame('Iz letka na tanjur', $view['hook_leaflet']);
        $this->assertTrue($view['has_leaflet']);
        $this->assertSame('Gotovo za 25 minuta', $view['hook_time']);
        $this->assertSame('25 min · 4 porcije · Vrijedi do 17. 11.', $view['meta']);
        $this->assertSame('4 od 7 sastojaka na akciji', $view['on_sale']);
        $this->assertSame('AI ilustracija', $view['ai_label']);
        $this->assertSame([['Na akciji', '4 / 7'], ['Zbroj', '8,96 €'], ['Ušteda', '2,20 €']], array_map(fn (array $tile): array => [$tile['label'], $tile['value']], $view['tiles']));
        $this->assertSame([], $view['highlights'], 'the ingredients are not shown unless the brand asks for them');

        $item->brand->voice = [...$item->brand->voice, 'recipe_highlights' => true];
        $this->assertSame(['Pileća prsa', 'Zelena mahuna', 'Vrhnje za kuhanje', 'Češnjak'], RecipeCopy::view($item, $item->brand)['highlights']);
    }

    public function test_a_saving_under_a_euro_is_not_on_a_slide_and_the_german_view_is_in_german(): void
    {
        $item = $this->recipeItem(feedItem: [...$this->recipeFeedItem(), 'raw' => ['recipe' => [...$this->recipeFeedItem()['raw']['recipe'], 'saving_cents' => 60]]]);
        $view = RecipeCopy::view($item, $item->brand);

        $this->assertNull($view['saving']);
        $this->assertNull($view['hook_saving']);
        $this->assertCount(2, $view['tiles']);

        $de = $this->recipeItem(market: 'at');
        $german = RecipeCopy::view($de, $de->brand);

        $this->assertSame('4 von 6 Zutaten im Angebot', $german['on_sale']);
        $this->assertSame('30 Min. · 4 Portionen · Gültig bis 18.11.', $german['meta']);
        $this->assertSame('KI-Illustration', $german['ai_label']);
        $this->assertSame('Ersparnis 2,70 €', $german['hook_saving']);
    }

    public function test_a_caption_on_every_channel_carries_the_numbers_and_says_the_picture_is_drawn(): void
    {
        $item = $this->recipeItem();
        $captions = app(CaptionBuilder::class);

        foreach ([Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok] as $platform) {
            $text = $captions->for($platform, $item, $item->brand);

            $this->assertStringContainsString('🍽️ Što kuhati ovaj tjedan? Pileća prsa s mahunama u vrhnju: 4 od 7 sastojaka je na akciji u trgovini Ribola, zajedno 8,96 €. Ušteda prema letku: 2,20 €.', $text, $platform->value);
            $this->assertStringContainsString('👇 Recept i popis su u aplikaciji Listo. Poveznica je u komentaru.', $text, $platform->value);
            // A question under the post, not an ask for a comment: a post that asks for one is ranked lower on Facebook.
            $this->assertStringContainsString('Hoće li ovo biti ručak ovaj tjedan?', $text, $platform->value);
            $this->assertStringContainsString('Slika jela je AI ilustracija.', $text, $platform->value);
            $this->assertStringNotContainsString('http', $text, 'the link is the first comment, never the text');
        }

        $this->assertStringContainsString('#listo', $captions->for(Platform::TikTok, $item, $item->brand));
    }

    public function test_the_caption_of_a_group_post_has_the_link_in_the_text_and_none_of_the_tags(): void
    {
        $item = $this->recipeItem();
        $text = app(CaptionBuilder::class)->for(Platform::FacebookGroup, $item, $item->brand);

        $this->assertStringContainsString("👉 Recept i popis za kupnju:\nhttps://uselisto.com/recept/pileca-prsa-s-mahunama-u-vrhnju-c7bd988657", $text);
        $this->assertStringNotContainsString('#', $text);
    }

    public function test_the_description_of_a_short_has_the_tracked_link_and_the_title_first(): void
    {
        $item = $this->recipeItem();
        $text = app(CaptionBuilder::class)->for(Platform::YouTube, $item, $item->brand);

        $this->assertStringStartsWith('Pileća prsa s mahunama u vrhnju', $text);
        $this->assertStringContainsString('🔗 https://uselisto.com/recept/pileca-prsa-s-mahunama-u-vrhnju-c7bd988657?utm_source=youtube&utm_medium=social&utm_campaign=recept-2026-W46', $text);
        $this->assertStringContainsString('#Shorts', $text);
        // The post a Short is says where its link is: in its own description.
        $this->assertStringContainsString('Poveznica je u opisu.', $text);
    }

    public function test_the_german_caption_is_german_and_free_of_croatian(): void
    {
        $item = $this->recipeItem(market: 'at');
        $text = app(CaptionBuilder::class)->for(Platform::InstagramBusiness, $item, $item->brand);

        $this->assertStringContainsString('🍽️ Was kochen diese Woche? Hendlfilet mit Spinat und Erdäpfeln: 4 von 6 Zutaten sind bei Eurospar im Angebot, zusammen 15,26 €. Ersparnis laut Prospekt: 2,70 €.', $text);
        $this->assertStringContainsString('Den Link findest du im Kommentar.', $text);
        $this->assertStringContainsString('Das Bild ist eine KI-Illustration.', $text);
        $this->assertStringContainsString('Wäre das dein Essen diese Woche?', $text);
        $this->assertStringNotContainsString('akciji', $text);
    }

    public function test_every_amount_a_caption_and_a_voice_say_is_in_the_items_data(): void
    {
        $validator = app(CaptionValidator::class);

        foreach (['hr' => [0, 1, 2, 3, 4], 'at' => [0, 1, 2, 3, 4]] as $market => $indexes) {
            $brand = $this->recipeBrand($market);

            foreach ($indexes as $index) {
                $item = $this->recipeItem($brand, $this->recipeFeedItem($index, $market), market: $market);
                $captions = app(CaptionBuilder::class);

                foreach ([Platform::FacebookPage, Platform::InstagramBusiness, Platform::TikTok, Platform::YouTube] as $platform) {
                    // The tracked link of a Short is added by the hub, after the words: it is the one thing a validator is not asked about.
                    $text = (string) preg_replace('/^🔗 .*$/mu', '', $captions->for($platform, $item, $item->brand));

                    $this->assertSame([], $validator->violationsInText($text, [$item]), "{$market} #{$index} {$platform->value}");
                }

                $this->assertSame([], $validator->violationsInText(implode(' ', RecipeCopy::voiceLines($item, $item->brand)), [$item]), "{$market} #{$index} voice");
            }
        }
    }

    public function test_the_first_comment_is_where_the_dish_and_the_list_are(): void
    {
        $item = $this->recipeItem();

        $this->assertSame('👉 Recept i popis za kupnju: uselisto.com/tiktok', RecipeCopy::comment('uselisto.com/tiktok', $item->brand));
        $de = $this->recipeItem(market: 'at');
        $this->assertSame('👉 Rezept und Einkaufsliste: https://uselisto.com/at/rezept/x', RecipeCopy::comment('https://uselisto.com/at/rezept/x', $de->brand));
    }

    public function test_the_numbers_are_said_by_the_speller_not_left_to_a_model(): void
    {
        $hr = $this->recipeItem();
        $croatian = app(SpokenCroatian::class)->speak(RecipeCopy::voiceLines($hr, $hr->brand)['card'], []);

        $this->assertStringContainsString('Četiri od sedam sastojaka je na akciji u trgovini Ribola.', $croatian);
        $this->assertDoesNotMatchRegularExpression('/\d/', $croatian);

        $at = $this->recipeItem(market: 'at');
        $german = app(SpokenGerman::class)->speak(RecipeCopy::voiceLines($at, $at->brand)['card'], []);

        $this->assertStringContainsString('vier von sechs Zutaten sind bei Eurospar im Angebot.', $german);
        $this->assertDoesNotMatchRegularExpression('/\d/', $german);
    }
}
