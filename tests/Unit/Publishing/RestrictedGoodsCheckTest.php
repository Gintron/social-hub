<?php

declare(strict_types=1);

namespace Tests\Unit\Publishing;

use App\Models\ContentItem;
use App\Publishing\RestrictedGoodsCheck;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a channel is not given. The cases that matter most look like drinks without being one (a soft
 * drink that says "bezalkoholno", vinegar, a sale called "žestoko"), and the ones that are drinks but
 * say so only in a percentage, or with a zero in front of the decimals.
 */
final class RestrictedGoodsCheckTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drinks(): iterable
    {
        yield 'wine' => ['Bijelo vino Graševina 1 l'];
        yield 'beer' => ['Ožujsko pivo 0,5 l'];
        yield 'spirit' => ['Rakija šljivovica 0,5 l'];
        yield 'spirit in another case' => ['Rakije šljivovice 0,5 l'];
        yield 'sparkling' => ['Prosecco Extra Dry 0,75 l'];
        yield 'herbal liqueur' => ['Pelinkovac 0,7 l'];
        yield 'brand of a drink' => ['Jägermeister 0,7 l'];
        yield 'imported brand' => ['Heineken 0,5 l'];
        yield 'variety' => ['Plavac mali 2019'];
        yield 'named as alcohol' => ['Sadrži alkohol'];
        yield 'by volume only' => ['Destilat 40 % vol. 0,7 l'];
        yield 'by volume with a zero digit' => ['Destilat 40,0 % vol. 0,7 l'];
        yield 'liqueur with a zero digit' => ['Likeri 20,0 % vol'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDrinks(): iterable
    {
        yield 'alcohol-free soft drink' => ['Coca-Cola bezalkoholno gazirano piće s dodatkom vitamina 2 l'];
        yield 'alcohol-free beer' => ['Bezalkoholno pivo 0,0 %'];
        yield 'alcohol-free wine' => ['Bezalkoholno vino 0,0 % vol.'];
        yield 'zero percent' => ['Radler 0,0 % vol. 0,5 l'];
        yield 'vinegar' => ['Vinski ocat 0,5 l'];
        yield 'a sale' => ['Žestoko sniženje na kruh 500 g'];
        yield 'a name' => ['Vinko Kovač, konobar'];
        yield 'a word inside a word' => ['Pivovara Medvedgrad, izlet'];
        yield 'a percentage that is not a volume' => ['Mlijeko 3,2 % masti'];
    }

    #[DataProvider('drinks')]
    public function test_names_a_drink(string $title): void
    {
        $this->assertNotNull($this->check()->itemProblem($this->item($title), 'tiktok'));
    }

    #[DataProvider('notDrinks')]
    public function test_leaves_alone_what_only_looks_like_a_drink(string $title): void
    {
        $this->assertNull($this->check()->itemProblem($this->item($title), 'tiktok'));
    }

    public function test_the_source_flag_counts_even_when_no_word_names_a_drink(): void
    {
        // A leaflet's title says nothing about its pages; the source knows which products are on them.
        $item = $this->item('Konzum katalog 13.10 – 19.10', ['restricted' => ['alcohol']]);

        $this->assertNotNull($this->check()->itemProblem($item, 'tiktok'));
        $this->assertNotNull($this->check()->itemProblem($item, 'ig_business'));
    }

    public function test_every_configured_channel_is_blocked_including_the_manual_group(): void
    {
        $item = $this->item('Ožujsko pivo 0,5 l');

        $this->assertNotNull($this->check()->itemProblem($item, 'fb_page'));
        $this->assertNotNull($this->check()->itemProblem($item, 'fb_group'), 'grupa je ručni kanal, ali je i dalje Facebook');
        $this->assertNotNull($this->check()->itemProblem($item, 'youtube'), 'YouTube je kanal kao i ostali');
        $this->assertNull($this->check()->itemProblem($item, 'some_other_channel'), 'kanal koji nije u popisu nije blokiran');
    }

    public function test_blocked_on_names_the_configured_channels_once_for_a_drink(): void
    {
        $check = $this->check();

        $this->assertSame(['fb_page', 'fb_group', 'ig_business', 'tiktok', 'youtube'], $check->blockedOn($this->item('Ožujsko pivo 0,5 l')));
        $this->assertSame([], $check->blockedOn($this->item('Kruh bijeli 500 g')));
    }

    public function test_a_channel_name_that_is_no_platform_is_an_error_not_a_silent_gap(): void
    {
        config()->set('restricted_goods.platforms', ['facebook_page']);

        $this->expectException(InvalidArgumentException::class);

        $this->check()->itemProblem($this->item('Ožujsko pivo 0,5 l'), 'fb_page');
    }

    public function test_an_empty_channel_list_blocks_nothing(): void
    {
        config()->set('restricted_goods.platforms', []);

        $this->assertNull($this->check()->itemProblem($this->item('Ožujsko pivo 0,5 l'), 'tiktok'));
    }

    public function test_an_empty_word_list_does_not_flag_every_item(): void
    {
        config()->set('restricted_goods.alcohol.terms', []);

        $this->assertNull($this->check()->itemProblem($this->item('Kruh bijeli 500 g'), 'tiktok'));
        $this->assertNotNull($this->check()->itemProblem($this->item('Destilat 40 % vol.'), 'tiktok'), 'postotak vol. ostaje');
    }

    public function test_an_empty_volume_pattern_does_not_flag_every_item(): void
    {
        config()->set('restricted_goods.alcohol.volume', '');

        $this->assertNull($this->check()->itemProblem($this->item('Kruh bijeli 500 g'), 'tiktok'));
    }

    public function test_the_words_come_from_config(): void
    {
        config()->set('restricted_goods.alcohol.terms', ['medenjak']);

        $this->assertNotNull($this->check()->itemProblem($this->item('Medenjak 200 g'), 'tiktok'));
        $this->assertNull($this->check()->itemProblem($this->item('Ožujsko pivo 0,5 l'), 'tiktok'), 'pivo više nije na popisu');
    }

    private function check(): RestrictedGoodsCheck
    {
        return new RestrictedGoodsCheck;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(string $title, array $attributes = []): ContentItem
    {
        return new ContentItem([
            'title' => $title,
            'subtitle' => null,
            'body_text' => null,
            'facts' => [],
            'badges' => [],
            'tags' => [],
            'raw' => null,
            ...$attributes,
        ]);
    }
}
