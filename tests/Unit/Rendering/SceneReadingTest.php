<?php

declare(strict_types=1);

namespace Tests\Unit\Rendering;

use App\Enums\ContentKind;
use App\Models\ContentItem;
use App\Rendering\Scene;
use Tests\TestCase;

/**
 * How long a slide has to stay up to be read. Only a quote asks for more than the default hold.
 */
final class SceneReadingTest extends TestCase
{
    public function test_a_short_quote_still_gets_enough_to_take_the_card_in(): void
    {
        $this->assertSame(4.0, $this->scene('Isus zaplaka.')->readingSeconds());
    }

    public function test_a_longer_quote_is_held_for_about_three_words_a_second(): void
    {
        // 18 words: a second to arrive, six to read.
        $text = 'Ljubi Gospodina Boga svojega svim srcem svojim i svom dušom svojom i svom snagom svojom i bližnjega svojega';

        $this->assertSame(7.0, $this->scene($text)->readingSeconds());
    }

    public function test_a_text_written_without_spaces_is_measured_by_its_characters(): void
    {
        // One "word" of 120 characters: a second to arrive and six to read, not the four-second floor.
        $this->assertSame(7.0, $this->scene(str_repeat('愛', 120))->readingSeconds());
    }

    public function test_the_hold_never_passes_what_a_slide_may_be_on_screen(): void
    {
        $this->assertSame(10.0, $this->scene(str_repeat('riječ ', 60))->readingSeconds());
    }

    public function test_only_the_card_of_a_quote_asks_for_time(): void
    {
        $item = $this->item(ContentKind::Quote, 'Isus zaplaka.');

        $this->assertNull((new Scene('kinds/cta-story', Scene::CLOSING, $item))->readingSeconds(), 'the end card keeps its own hold');
        $this->assertNull((new Scene('kinds/deal-story', Scene::CARD, $this->item(ContentKind::Deal, str_repeat('riječ ', 60))))->readingSeconds());
        $this->assertNull((new Scene('kinds/digest-cover-story', Scene::COVER))->readingSeconds());
    }

    private function scene(string $text): Scene
    {
        return new Scene('kinds/quote-story', Scene::CARD, $this->item(ContentKind::Quote, $text));
    }

    private function item(ContentKind $kind, string $title): ContentItem
    {
        return new ContentItem(['kind' => $kind, 'title' => $title]);
    }
}
