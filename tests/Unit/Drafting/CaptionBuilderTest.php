<?php

declare(strict_types=1);

namespace Tests\Unit\Drafting;

use App\Drafting\CaptionBuilder;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use Tests\TestCase;

final class CaptionBuilderTest extends TestCase
{
    public function test_facebook_caption_mirrors_the_group_post_layout(): void
    {
        $item = $this->item();
        $brand = new Brand(['name' => 'Studentski poslovi', 'site_url' => 'https://studentski-poslovi.hr', 'voice' => ['hashtags' => ['studentskiposao']]]);

        $caption = (new CaptionBuilder)->facebook($item, $brand);

        $this->assertStringStartsWith('🍽️ Konobar/ica', $caption);
        $this->assertStringContainsString('Hotel Adriatic', $caption);
        $this->assertStringContainsString(CaptionBuilder::bold('LOKACIJA:').' SPLIT', $caption);
        $this->assertStringContainsString('✅ Sezonski posao', $caption);
        $this->assertStringContainsString('• Posluživanje', $caption);
        $this->assertStringEndsWith('https://example.test/posao/1', $caption);
        $this->assertStringContainsString(CaptionBuilder::bold('Detalji o poslu').':', $caption);
    }

    public function test_instagram_caption_has_hashtags_and_no_raw_link(): void
    {
        $item = $this->item();
        $brand = new Brand(['name' => 'Studentski poslovi', 'site_url' => 'https://studentski-poslovi.hr', 'voice' => ['hashtags' => ['#studentskiposao']]]);

        $caption = (new CaptionBuilder)->instagram($item, $brand);

        $this->assertStringContainsString('🔗 Link u biu → example.test', $caption);
        $this->assertStringNotContainsString('https://', $caption);
        $this->assertStringContainsString('#studentskiposao', $caption);
        $this->assertStringContainsString('#split', $caption);
        $this->assertStringContainsString('#posao', $caption);
        $this->assertLessThanOrEqual(2200, mb_strlen($caption));
    }

    public function test_instagram_opens_with_the_title_and_the_hook(): void
    {
        $item = $this->item(['price' => ['current_cents' => 700, 'unit_label' => '€/H']]);
        $brand = new Brand(['name' => 'Studentski poslovi', 'site_url' => 'https://studentski-poslovi.hr']);

        $lines = explode("\n", (new CaptionBuilder)->instagram($item, $brand));

        $this->assertSame('🍽️ Konobar/ica', $lines[0]);
        $this->assertSame('💰 7.00 €/H · Split', $lines[1]);
        $this->assertStringContainsString('📤 Pošalji prijatelju', implode("\n", $lines));
    }

    public function test_tiktok_puts_the_pitch_in_the_title_line_and_keeps_links_out(): void
    {
        $item = $this->item(['price' => ['current_cents' => 700, 'unit_label' => '€/H'], 'tags' => ['a', 'b', 'c', 'd', 'e', 'f']]);
        $brand = new Brand(['name' => 'Studentski poslovi', 'site_url' => 'https://studentski-poslovi.hr', 'voice' => ['hashtags' => ['studentskiposao']]]);

        $caption = (new CaptionBuilder)->for(Platform::TikTok, $item, $brand);

        $this->assertStringStartsWith("🍽️ Konobar/ica · 7.00 €/H\n", $caption);
        $this->assertStringNotContainsString('https://', $caption);
        $this->assertSame(5, preg_match_all('/#\w+/u', $caption));
    }

    public function test_the_brands_pitch_goes_where_the_link_is_not_clickable(): void
    {
        $item = $this->item(['price' => ['current_cents' => 100, 'old_cents' => 189, 'discount_pct' => 47], 'subtitle' => 'Konzum']);
        $brand = new Brand([
            'name' => 'Listo',
            'site_url' => 'https://uselisto.com',
            'voice' => ['pitch' => 'Svi letci na jednom mjestu: dodirni proizvod i on je na listi.'],
        ]);
        $builder = new CaptionBuilder;

        foreach ([Platform::TikTok, Platform::InstagramBusiness] as $platform) {
            $caption = $builder->for($platform, $item, $brand);
            $pitch = mb_strpos($caption, '📲 Svi letci na jednom mjestu: dodirni proizvod i on je na listi.');

            $this->assertNotFalse($pitch, $platform->value);
            $this->assertLessThan(mb_strpos($caption, 'Link u biu'), $pitch, 'razlog ide prije upute na profil');
        }

        $digest = $builder->digest(Platform::TikTok, collect([$item]), $brand, 'Top akcije');
        $this->assertStringContainsString('📲 Svi letci na jednom mjestu', $digest);

        // Na Facebooku je poveznica klikabilna i vodi na samu ponudu; rečenica bi samo produžila tekst.
        $this->assertStringNotContainsString('📲', $builder->for(Platform::FacebookPage, $item, $brand));
        $this->assertNull($builder->pitch(new Brand(['voice' => ['pitch' => '  ']])));
    }

    public function test_the_page_caption_links_the_listing_and_leaves_contacts_to_the_group_post(): void
    {
        $item = $this->item(['price' => ['current_cents' => 700, 'unit_label' => '€/H'], 'cta' => ['label' => 'Prijavi se', 'url' => 'https://example.test/posao/1']]);
        $brand = new Brand(['name' => 'Studentski poslovi', 'voice' => ['hashtags' => ['studentskiposao']]]);

        $page = (new CaptionBuilder)->for(Platform::FacebookPage, $item, $brand);
        $group = (new CaptionBuilder)->for(Platform::FacebookGroup, $item, $brand);

        $this->assertStringContainsString('💰 7.00 €/H · Split', $page);
        $this->assertStringContainsString(CaptionBuilder::bold('Prijavi se').":\nhttps://example.test/posao/1", $page);
        $this->assertStringEndsWith('#studentskiposao', $page);
        $this->assertStringNotContainsString('posao@example.test', $page);
        $this->assertStringContainsString('posao@example.test', $group);
    }

    public function test_hashtags_are_unique_lowercase_and_capped(): void
    {
        $item = $this->item(['tags' => array_map(fn (int $i): string => "tag{$i}", range(1, 40))]);
        $brand = new Brand(['voice' => ['hashtags' => ['Tag1', 'tag1']]]);

        $tags = (new CaptionBuilder)->hashtags($item, $brand);

        $this->assertCount(30, $tags);
        $this->assertSame('#tag1', $tags[0]);
        $this->assertCount(count($tags), array_unique($tags));
    }

    public function test_unicode_bold_only_touches_ascii_letters_and_digits(): void
    {
        $this->assertSame('𝗔𝗕𝗖 čšž 𝟭𝟮', CaptionBuilder::bold('ABC čšž 12'));
    }

    public function test_digest_shortens_catalogue_titles_and_asks_for_a_save_or_share(): void
    {
        $item = $this->item(['title' => str_repeat('Vrlo dugačak naziv proizvoda ', 5)]);
        $brand = new Brand(['site_url' => 'https://uselisto.com']);

        $caption = (new CaptionBuilder)->digest(Platform::InstagramBusiness, collect([$item]), $brand, 'Top ponude');

        $this->assertStringContainsString('💾 Spremi popis', $caption);
        $this->assertStringContainsString('Link u biu → uselisto.com', $caption);
        $this->assertLessThan(260, mb_strlen($caption));
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
            'body_text' => "• Posluživanje\n• Rad u smjenama\n\nNačin prijave:\nposao@example.test",
            'facts' => [['label' => 'LOKACIJA', 'value' => 'SPLIT'], ['label' => 'SATNICA', 'value' => '7.00 €/H']],
            'badges' => ['Sezonski posao', 'Smještaj'],
            'url' => 'https://example.test/posao/1',
            'tags' => ['konobar', 'split'],
            'raw' => ['emoji' => '🍽️'],
            'images' => [],
        ], $overrides));
    }
}
