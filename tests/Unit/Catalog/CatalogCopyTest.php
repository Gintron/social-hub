<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\CatalogCopy;
use App\Catalog\CatalogDemo;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use PHPUnit\Framework\TestCase;

/**
 * Every word of the video and of the post around it comes from here. The call to action is the owner's
 * (3 Oct 2026): where the link is — never what the app costs or saves.
 */
final class CatalogCopyTest extends TestCase
{
    public function test_the_voice_says_what_the_owner_wrote_with_the_chains_own_form(): void
    {
        $this->assertSame([
            'Hej, izašao je novi Konzumov katalog.',
            'Prelistaj ga i dodaj proizvod na svoju listu dodirom na letak.',
            'Preuzmi Listo.',
            'Poveznica do aplikacije je u komentaru.',
        ], CatalogCopy::voiceLines($this->demo(), cta: 'Preuzmi Listo'));
    }

    public function test_a_chain_in_the_plural_keeps_its_own_phrase_and_an_unknown_one_a_safe_one(): void
    {
        $plodine = CatalogDemo::fromArray([...$this->raw(), 'chain_phrase' => 'katalog Plodina']);
        $unknown = CatalogDemo::fromArray([...$this->raw(), 'chain_phrase' => null, 'chain_name' => 'Studenac']);

        $this->assertSame('Hej, izašao je novi katalog Plodina.', CatalogCopy::voiceLines($plodine)[0]);
        $this->assertSame('Hej, izašao je novi katalog trgovine Studenac.', CatalogCopy::voiceLines($unknown)[0]);
        $this->assertSame(['Izašao je novi', 'katalog Plodina'], CatalogCopy::title($plodine));
    }

    public function test_the_fallback_for_a_channel_without_comments_says_the_link_is_in_the_bio(): void
    {
        $lines = CatalogCopy::voiceLines($this->demo(), CatalogCopy::WHERE_BIO);

        $this->assertSame('Poveznica do aplikacije je u biografiji.', $lines[3]);
        $this->assertSame('Poveznica do aplikacije je u biografiji.', CatalogCopy::linkSentence(CatalogCopy::WHERE_BIO));
    }

    public function test_the_validity_is_the_leaflets_own_dates(): void
    {
        $this->assertSame('Vrijedi od 7. 10. do 13. 10. 2026.', CatalogCopy::validity($this->demo()));

        $overNewYear = CatalogDemo::fromArray([...$this->raw(), 'valid_from' => '2026-12-28', 'valid_to' => '2027-01-03']);
        $this->assertSame('Vrijedi od 28. 12. 2026. do 3. 1. 2027.', CatalogCopy::validity($overNewYear));
    }

    public function test_the_caption_says_what_to_do_and_where_the_link_is_and_promises_nothing_else(): void
    {
        $caption = CatalogCopy::caption(Platform::InstagramBusiness, $this->item(), $this->brand(), $this->demo());

        $this->assertStringContainsString('🛒 Izašao je novi Konzumov katalog', $caption);
        $this->assertStringContainsString('Vrijedi od 7. 10. do 13. 10. 2026. Prelistaj ga i dodaj proizvod na svoju listu dodirom na letak.', $caption);
        $this->assertStringContainsString('👇 Preuzmi Listo. Poveznica do aplikacije je u komentaru.', $caption);
        $this->assertStringContainsString('#listo', $caption);
        $this->assertStringNotContainsString('http', $caption);
        $this->assertStringNotContainsString('besplatno', $caption, 'the owner chose the link, not the trial');
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(€|eur|%)/iu', $caption, 'no amount or percentage that was not checked against the item');
    }

    public function test_a_facebook_page_gets_fewer_hashtags(): void
    {
        $brand = new Brand(['name' => 'Listo', 'voice' => ['cta' => 'Preuzmi Listo', 'hashtags' => ['a', 'b', 'c', 'd', 'e', 'f']]]);

        $page = CatalogCopy::caption(Platform::FacebookPage, $this->item(), $brand, $this->demo());
        $instagram = CatalogCopy::caption(Platform::InstagramBusiness, $this->item(), $brand, $this->demo());

        $this->assertSame(3, preg_match_all('/#\w+/u', $page));
        $this->assertSame(5, preg_match_all('/#\w+/u', $instagram));
    }

    public function test_the_first_comment_is_the_tracked_link_under_the_brands_own_call(): void
    {
        $comment = CatalogCopy::comment($this->demo(), 'https://uselisto.com/app?utm_source=facebook', $this->brand());

        $this->assertSame('👉 Preuzmi Listo: https://uselisto.com/app?utm_source=facebook', $comment);
    }

    private function brand(): Brand
    {
        return new Brand(['name' => 'Listo', 'voice' => ['cta' => 'Preuzmi Listo', 'hashtags' => ['listo']]]);
    }

    private function item(): ContentItem
    {
        return new ContentItem(['tags' => ['konzum', 'katalog']]);
    }

    private function demo(): CatalogDemo
    {
        return CatalogDemo::fromArray($this->raw());
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        $payload = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/catalog-feed-konzum-2026-10-07.json'), true, flags: JSON_THROW_ON_ERROR);

        return $payload['items'][0]['raw']['demo'];
    }
}
