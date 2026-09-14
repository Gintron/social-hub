<?php

declare(strict_types=1);

namespace Tests\Unit\Drafting;

use App\Drafting\Highlights;
use App\Enums\ContentKind;
use App\Models\ContentItem;
use Tests\TestCase;

final class HighlightsTest extends TestCase
{
    public function test_the_figure_keeps_the_sites_own_wording_when_a_fact_states_the_price(): void
    {
        $item = $this->item([
            'facts' => [['label' => 'LOKACIJA', 'value' => 'LOVRAN'], ['label' => 'SATNICA', 'value' => '7.00 - 8.00 €/H']],
            'price' => ['current_cents' => 700, 'unit_label' => '€/H'],
        ]);

        $this->assertSame(['value' => '7.00 - 8.00 €/H', 'label' => 'SATNICA', 'fact' => 'SATNICA', 'old' => null], Highlights::figure($item));
        $this->assertSame(['Lovran'], Highlights::points($item));
    }

    public function test_a_fact_that_only_repeats_the_price_neither_labels_it_nor_repeats_it(): void
    {
        $deal = $this->item([
            'kind' => ContentKind::Deal,
            'facts' => [['label' => 'NAJNIŽA U 30 DANA', 'value' => '1,89 €'], ['label' => 'VRIJEDI DO', 'value' => '8.9.2026.']],
            'price' => ['current_cents' => 189, 'old_cents' => 359, 'discount_pct' => 47],
        ]);

        $this->assertSame(['value' => '1,89 €', 'label' => '−47 %', 'fact' => null, 'old' => '3,59 €'], Highlights::figure($deal));
        $this->assertSame(['8.9.2026.'], Highlights::points($deal));
    }

    public function test_a_whole_euro_price_is_not_found_inside_another_amount(): void
    {
        $deal = $this->item([
            'kind' => ContentKind::Deal,
            'facts' => [['label' => 'JEDINIČNA CIJENA', 'value' => '6,25 €/kg'], ['label' => 'NAJNIŽA U 30 DANA', 'value' => '1,89 €']],
            'price' => ['current_cents' => 100, 'old_cents' => 189, 'discount_pct' => 47],
        ]);

        // "1,89 €" starts with a 1, but it is not the 1,00 € the deal costs.
        $this->assertSame(['value' => '1,00 €', 'label' => '−47 %', 'fact' => null, 'old' => '1,89 €'], Highlights::figure($deal));
        $this->assertSame(['6,25 €/kg'], Highlights::points($deal), 'goli iznos bez oznake ne znači ništa');
    }

    public function test_the_figure_is_formatted_from_the_price_when_no_fact_states_it(): void
    {
        $deal = $this->item([
            'kind' => ContentKind::Deal,
            'facts' => [['label' => 'Jedinična cijena', 'value' => '17,00 €/kg']],
            'price' => ['current_cents' => 199, 'old_cents' => 349, 'discount_pct' => 43],
        ]);

        // 17,00 must not be read as 7,00 or 1,99; the old price comes along to be struck through.
        $this->assertSame(['value' => '1,99 €', 'label' => '−43 %', 'fact' => null, 'old' => '3,49 €'], Highlights::figure($deal));
        $this->assertSame(['17,00 €/kg'], Highlights::points($deal));
    }

    public function test_an_item_without_a_price_has_no_figure_and_shouting_values_are_tidied(): void
    {
        $item = $this->item([
            'facts' => [['label' => 'LOKACIJA', 'value' => 'RAD OD KUĆE'], ['label' => 'SATNICA', 'value' => 'Po dogovoru'], ['label' => 'KATEGORIJA', 'value' => 'IT']],
            'price' => null,
        ]);

        $this->assertNull(Highlights::figure($item));
        $this->assertSame(['Rad Od Kuće', 'Po dogovoru'], Highlights::points($item));
        $this->assertSame('ZAGREB 10000', Highlights::tidy('ZAGREB 10000'), 'vrijednosti s brojevima ostaju kakve jesu');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(array $overrides): ContentItem
    {
        return new ContentItem(array_replace([
            'kind' => ContentKind::Job,
            'title' => 'Konobar/ica',
            'facts' => [],
            'badges' => [],
            'url' => 'https://example.test/posao/1',
        ], $overrides));
    }
}
