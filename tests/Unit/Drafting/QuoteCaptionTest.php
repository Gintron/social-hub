<?php

declare(strict_types=1);

namespace Tests\Unit\Drafting;

use App\Drafting\CaptionBuilder;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use Tests\TestCase;

/**
 * A quote is posted as itself. The hub writes no word of its own into it, so the same builder serves a
 * brand in any language; what stands under the words is what the source and the brand sent.
 */
final class QuoteCaptionTest extends TestCase
{
    public function test_every_channel_opens_with_the_words_and_whose_they_are(): void
    {
        foreach ([Platform::FacebookPage, Platform::FacebookGroup, Platform::InstagramBusiness, Platform::TikTok] as $platform) {
            $caption = (new CaptionBuilder)->for($platform, $this->quote(), $this->brand());

            $this->assertStringStartsWith("Ljubi bližnjega svoga kao samoga sebe.\n— Mk 12,31", $caption, $platform->value);
        }
    }

    public function test_the_hub_adds_no_wording_of_its_own(): void
    {
        $item = $this->quote(['cta' => null, 'tags' => []]);

        foreach ([Platform::FacebookPage, Platform::FacebookGroup, Platform::InstagramBusiness, Platform::TikTok] as $platform) {
            $caption = (new CaptionBuilder)->for($platform, $item, $this->brand(['hashtags' => []]));

            $this->assertSame("Ljubi bližnjega svoga kao samoga sebe.\n— Mk 12,31", $caption, $platform->value);
        }
    }

    public function test_where_the_words_are_from_stands_under_the_reference_on_every_channel(): void
    {
        $item = $this->quote(['body_text' => 'Jeruzalemska Biblija, Kršćanska sadašnjost']);

        foreach ([Platform::FacebookPage, Platform::FacebookGroup, Platform::InstagramBusiness, Platform::TikTok] as $platform) {
            $caption = (new CaptionBuilder)->for($platform, $item, $this->brand(['hashtags' => []]));

            $this->assertStringStartsWith("Ljubi bližnjega svoga kao samoga sebe.\n— Mk 12,31\n\nJeruzalemska Biblija, Kršćanska sadašnjost", $caption, $platform->value);
        }
    }

    public function test_a_page_points_at_the_first_comment_with_the_sources_own_label_and_a_group_pastes_the_link(): void
    {
        $item = $this->quote(['cta' => ['label' => 'Read the whole chapter', 'url' => 'https://verses.test/mk/12']]);

        $page = (new CaptionBuilder)->for(Platform::FacebookPage, $item, $this->brand());
        $group = (new CaptionBuilder)->for(Platform::FacebookGroup, $item, $this->brand());
        $reel = (new CaptionBuilder)->for(Platform::InstagramBusiness, $item, $this->brand());

        $this->assertStringContainsString('👇 '.CaptionBuilder::bold('Read the whole chapter'), $page);
        $this->assertStringNotContainsString('https://', $page, 'the Page keeps the link for the first comment');
        $this->assertStringContainsString(CaptionBuilder::bold('Read the whole chapter').":\nhttps://verses.test/mk/12", $group);
        $this->assertStringNotContainsString('Read the whole chapter', $reel, 'a link is not clickable there');
        $this->assertStringNotContainsString('https://', $reel);
    }

    public function test_hashtags_are_the_brands_own_and_the_items_tags_a_few_per_channel(): void
    {
        $item = $this->quote(['tags' => ['evanđelje', 'ljubav', 'bližnji', 'vjera', 'nada', 'milosrđe']]);
        $brand = $this->brand(['hashtags' => ['#vjera', 'rijecbozja']]);

        $page = (new CaptionBuilder)->for(Platform::FacebookPage, $item, $brand);
        $tiktok = (new CaptionBuilder)->for(Platform::TikTok, $item, $brand);
        $group = (new CaptionBuilder)->for(Platform::FacebookGroup, $item, $brand);

        $this->assertStringEndsWith('#vjera #rijecbozja', $page, 'a Page carries the brand\'s fixed tags only');
        $this->assertSame(5, mb_substr_count($tiktok, '#'));
        // The item's tags are slugged like every hashtag the hub writes: no diacritics.
        $this->assertStringContainsString('#vjera #rijecbozja #evandelje #ljubav #bliznji', $tiktok);
        $this->assertStringNotContainsString('#', $group);
    }

    public function test_the_brands_pitch_goes_to_the_networks_that_cannot_link(): void
    {
        $brand = $this->brand(['pitch' => 'Jedan stih svaki dan.']);

        $this->assertStringContainsString('📲 Jedan stih svaki dan.', (new CaptionBuilder)->for(Platform::InstagramBusiness, $this->quote(), $brand));
        $this->assertStringNotContainsString('Jedan stih', (new CaptionBuilder)->for(Platform::FacebookPage, $this->quote(), $brand));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function quote(array $overrides = []): ContentItem
    {
        return new ContentItem(array_replace([
            'kind' => ContentKind::Quote,
            'title' => 'Ljubi bližnjega svoga kao samoga sebe.',
            'subtitle' => 'Mk 12,31',
            'url' => 'https://verses.test/mk/12',
            'cta' => null,
            'tags' => [],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $voice
     */
    private function brand(array $voice = []): Brand
    {
        return new Brand(['name' => 'Riječ', 'site_url' => 'https://rijec.test', 'voice' => $voice]);
    }
}
