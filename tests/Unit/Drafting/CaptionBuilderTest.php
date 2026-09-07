<?php

declare(strict_types=1);

namespace Tests\Unit\Drafting;

use App\Drafting\CaptionBuilder;
use App\Enums\ContentKind;
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
