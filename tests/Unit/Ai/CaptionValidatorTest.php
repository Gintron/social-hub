<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\CaptionValidator;
use App\Ai\FakeCaptionWriter;
use App\Enums\ContentKind;
use App\Models\ContentItem;
use Tests\TestCase;

/**
 * The rule that matters: a post may only state what the source said. Everything here is a way a
 * caption could quietly become a false advertisement.
 */
final class CaptionValidatorTest extends TestCase
{
    private CaptionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new CaptionValidator;
    }

    public function test_captions_built_from_the_items_own_facts_pass(): void
    {
        $captions = FakeCaptionWriter::captions(
            facebook: 'Konobar/ica u Splitu, 7,00 €/H. Prijave: https://example.test/posao/1',
            instagram: 'Konobar/ica u Splitu, 7,00 €/H. Link u biu.',
        );

        $this->assertSame([], $this->validator->validate($captions, $this->item()));
    }

    public function test_an_invented_wage_is_caught(): void
    {
        $captions = FakeCaptionWriter::captions(
            facebook: 'Konobar/ica u Splitu, čak 12,00 €/H!',
            instagram: 'Konobar/ica u Splitu.',
        );

        $violations = $this->validator->validate($captions, $this->item());

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('12,00 €', $violations[0]);
    }

    public function test_an_invented_discount_is_caught(): void
    {
        $captions = FakeCaptionWriter::captions(facebook: 'Sniženje čak 70 %!', instagram: 'Sniženje.');

        $violations = $this->validator->validate($captions, $this->item());

        $this->assertStringContainsString('70 %', implode(' ', $violations));
    }

    public function test_a_discount_the_item_states_is_allowed(): void
    {
        $item = $this->item(['price' => ['current_cents' => 199, 'old_cents' => 499, 'discount_pct' => 60, 'currency' => 'EUR', 'unit_label' => null]]);

        $captions = FakeCaptionWriter::captions(facebook: 'Sniženje 60 %, sada 1,99 € umjesto 4,99 €.', instagram: 'Sniženje 60 %.');

        $this->assertSame([], $this->validator->validate($captions, $item));
    }

    public function test_an_invented_link_is_caught(): void
    {
        $captions = FakeCaptionWriter::captions(
            facebook: 'Prijave na https://phishing.example/prijava',
            instagram: 'Prijave u biu.',
        );

        $this->assertStringContainsString('phishing.example', implode(' ', $this->validator->validate($captions, $this->item())));
    }

    public function test_a_link_in_an_instagram_caption_is_caught(): void
    {
        $captions = FakeCaptionWriter::captions(
            facebook: 'Detalji: https://example.test/posao/1',
            instagram: 'Detalji: https://example.test/posao/1',
        );

        $this->assertStringContainsString('nije klikabilna', implode(' ', $this->validator->validate($captions, $this->item())));
    }

    public function test_length_and_hashtag_limits_are_enforced(): void
    {
        $captions = FakeCaptionWriter::captions(
            facebook: 'Kratko.',
            instagram: str_repeat('a', 2201),
            hashtags: implode(' ', array_map(fn (int $i): string => "#tag{$i}", range(1, 31))),
        );

        $violations = implode(' ', $this->validator->validate($captions, $this->item()));

        $this->assertStringContainsString('2200', $violations);
        $this->assertStringContainsString('30', $violations);
    }

    public function test_an_empty_caption_is_a_violation(): void
    {
        $captions = FakeCaptionWriter::captions(facebook: '   ', instagram: 'Nešto.');

        $this->assertStringContainsString('prazan', implode(' ', $this->validator->validate($captions, $this->item())));
    }

    public function test_numbers_that_are_not_money_are_left_alone(): void
    {
        // "u 2 smjene" and "od 8 do 16" are ordinary prose, not price claims.
        $captions = FakeCaptionWriter::captions(
            facebook: 'Rad u 2 smjene, od 8 do 16 sati. Satnica 7,00 €/H.',
            instagram: 'Rad u 2 smjene.',
        );

        $this->assertSame([], $this->validator->validate($captions, $this->item()));
    }

    public function test_thousands_and_decimal_separators_are_understood(): void
    {
        $item = $this->item([
            'facts' => [['label' => 'PLAĆA', 'value' => '1.500,00 € bruto']],
            'price' => null,
        ]);

        $stated = FakeCaptionWriter::captions(facebook: 'Plaća 1.500,00 € bruto.', instagram: 'Plaća 1.500,00 €.');
        $this->assertSame([], $this->validator->validate($stated, $item));

        $invented = FakeCaptionWriter::captions(facebook: 'Plaća 2.500,00 € bruto.', instagram: 'Dobro plaćeno.');
        $this->assertNotSame([], $this->validator->validate($invented, $item));
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
            'body_text' => "• Posluživanje gostiju\n• Rad u smjenama",
            'facts' => [['label' => 'LOKACIJA', 'value' => 'SPLIT'], ['label' => 'SATNICA', 'value' => '7,00 €/H']],
            'badges' => ['Sezonski posao'],
            'price' => ['current_cents' => 700, 'old_cents' => null, 'discount_pct' => null, 'currency' => 'EUR', 'unit_label' => '€/H'],
            'url' => 'https://example.test/posao/1',
            'cta' => ['label' => 'Prijavi se', 'url' => 'https://example.test/posao/1'],
            'images' => [],
            'tags' => ['split'],
            'raw' => [],
        ], $overrides));
    }
}
