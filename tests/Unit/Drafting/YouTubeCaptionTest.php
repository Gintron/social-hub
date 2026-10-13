<?php

declare(strict_types=1);

namespace Tests\Unit\Drafting;

use App\Drafting\CaptionBuilder;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Publishing\YouTube\YouTubePreflight;
use App\Support\Utf16;
use Tests\TestCase;

final class YouTubeCaptionTest extends TestCase
{
    public function test_the_first_line_is_the_title_and_the_rest_is_the_description(): void
    {
        $caption = (new CaptionBuilder)->youtube($this->item(), $this->brand());

        [$title, $description] = YouTubePreflight::split($caption);

        $this->assertStringStartsWith('🍽️ Konobar/ica', $title);
        $this->assertStringContainsString('Hotel Adriatic', $description);
        $this->assertLessThanOrEqual(100, Utf16::length($title));
    }

    public function test_a_short_keeps_its_link_and_says_it_is_a_short(): void
    {
        $caption = (new CaptionBuilder)->youtube($this->item(), $this->brand());

        // Unlike TikTok, a YouTube description has a clickable link: the item's page goes in.
        $this->assertStringContainsString('🔗 https://example.test/posao/1', $caption);
        $this->assertStringNotContainsString('Link u biu', $caption);
        $this->assertStringContainsString('#Shorts', $caption);
    }

    public function test_a_long_headline_is_cut_to_the_title_limit_with_an_ellipsis(): void
    {
        $caption = (new CaptionBuilder)->youtube($this->item(['title' => str_repeat('Vrlo dugačak naslov ', 12)]), $this->brand());

        [$title] = YouTubePreflight::split($caption);

        $this->assertLessThanOrEqual(100, Utf16::length($title));
        $this->assertStringEndsWith('…', $title);
    }

    public function test_an_agent_text_becomes_the_title_and_description_with_the_link_and_tags_added(): void
    {
        $agentText = "Konobar/ica u Splitu, 7,00 €/H\n\nPosluživanje i rad u smjenama. Prijavi se do petka.";

        $caption = (new CaptionBuilder)->youtube($this->item(), $this->brand(), $agentText);

        [$title, $description] = YouTubePreflight::split($caption);

        $this->assertSame('Konobar/ica u Splitu, 7,00 €/H', $title);
        $this->assertStringStartsWith('Posluživanje i rad u smjenama.', $description);
        $this->assertStringContainsString('🔗 https://example.test/posao/1', $description);
        $this->assertStringEndsWith('#Shorts #studentskiposao #konobar #split #posao', $caption);
    }

    public function test_a_youtube_digest_is_plain_text_with_the_brand_page_and_the_short_tag(): void
    {
        $items = collect([$this->item(), $this->item(['title' => 'Kuhar/ica'])]);

        $caption = (new CaptionBuilder)->digest(Platform::YouTube, $items, $this->brand(), 'Top 2 posla ovog tjedna');

        // Plain, not Unicode bold: a title and a search read the letters as typed.
        $this->assertStringStartsWith('Top 2 posla ovog tjedna', $caption);
        $this->assertStringContainsString('1. Konobar/ica', $caption);
        $this->assertStringContainsString('Sve ponude: https://studentski-poslovi.hr', $caption);
        $this->assertStringNotContainsString('Link u biu', $caption);
        $this->assertStringEndsWith('#Shorts #studentskiposao #konobar #split #posao', $caption);
    }

    public function test_a_quote_on_a_short_has_its_words_cut_to_the_title_limit_and_says_it_is_a_short(): void
    {
        $item = $this->item([
            'kind' => ContentKind::Quote,
            'title' => str_repeat('Ljubav je strpljiva, ljubav je dobrostiva. ', 5),
            'subtitle' => 'Iv 3,16',
            'body_text' => 'Jer tako uzljubi Bog svijet.',
            'url' => null,
            'cta' => [],
        ]);

        $caption = (new CaptionBuilder)->for(Platform::YouTube, $item, $this->brand());

        [$title, $description] = YouTubePreflight::split($caption);

        // The words are the title of the Short; the reference and the text under them are the description.
        $this->assertLessThanOrEqual(100, Utf16::length($title));
        $this->assertStringEndsWith('…', $title);
        $this->assertStringContainsString('Iv 3,16', $description);
        $this->assertStringContainsString('#Shorts', $caption);
    }

    public function test_the_builder_for_youtube_is_the_youtube_caption(): void
    {
        $item = $this->item();
        $brand = $this->brand();

        $this->assertSame(
            (new CaptionBuilder)->youtube($item, $brand),
            (new CaptionBuilder)->for(Platform::YouTube, $item, $brand),
        );
    }

    private function brand(): Brand
    {
        return new Brand([
            'name' => 'Studentski poslovi',
            'site_url' => 'https://studentski-poslovi.hr',
            'voice' => ['hashtags' => ['studentskiposao']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(array $overrides = []): ContentItem
    {
        return new ContentItem(array_replace([
            'kind' => ContentKind::Job,
            'title' => 'Konobar/ica',
            'subtitle' => 'Hotel Adriatic',
            'body_text' => "• Posluživanje\n• Rad u smjenama",
            'facts' => [['label' => 'LOKACIJA', 'value' => 'SPLIT'], ['label' => 'SATNICA', 'value' => '7.00 €/H']],
            'badges' => ['Sezonski posao'],
            'url' => 'https://example.test/posao/1',
            'tags' => ['konobar', 'split'],
            'raw' => ['emoji' => '🍽️'],
            'images' => [],
        ], $overrides));
    }
}
