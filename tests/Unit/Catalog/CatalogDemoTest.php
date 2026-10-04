<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\CatalogDemo;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * `raw.demo` is read, never invented: a block that cannot be shown as it is must be refused with its reason,
 * not drawn with a guess — above all the total, which has to be the sum of the prices on the list.
 */
final class CatalogDemoTest extends TestCase
{
    public function test_the_real_konzum_feed_reads_into_three_taps_and_a_true_total(): void
    {
        $demo = CatalogDemo::fromArray($this->demo());

        $this->assertSame('Konzum', $demo->chainName);
        $this->assertSame('Konzumov katalog', $demo->phrase());
        $this->assertSame([1, 5, 6], array_map(fn (array $page): int => $page['number'], $demo->pages));
        $this->assertSame(['Pileći zabatak', 'Pureće mljeveno meso', 'Topljeni sir'], array_map(fn (array $tap): string => $tap['name'], $demo->taps));
        $this->assertSame(379 + 479 + 179, $demo->totalCents);
        $this->assertSame('2026-10-07', $demo->validFrom->format('Y-m-d'));
        $this->assertSame([6], $demo->tapPages());
    }

    public function test_a_chain_without_a_phrase_is_a_catalog_of_the_shop_never_a_guessed_case(): void
    {
        $demo = CatalogDemo::fromArray([...$this->demo(), 'chain_phrase' => null, 'chain_name' => 'Studenac']);

        $this->assertSame('katalog trgovine Studenac', $demo->phrase());
    }

    public function test_a_total_that_is_not_the_sum_of_the_prices_is_refused(): void
    {
        $problems = CatalogDemo::problems([...$this->demo(), 'total_cents' => 1000]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('nije zbroj cijena dodira (1037)', $problems[0]);
    }

    public function test_the_block_names_every_reason_it_cannot_be_a_video(): void
    {
        $demo = $this->demo();
        $demo['taps'][0]['crop'] = '/files/relative.webp';
        $demo['taps'][1]['page'] = 99;
        $demo['taps'][2]['name'] = $demo['taps'][1]['name'];
        $demo['pages'][0]['url'] = 'not a url';

        $problems = implode(' ', CatalogDemo::problems($demo));

        $this->assertStringContainsString('Stranica 1: url mora biti apsolutan', $problems);
        $this->assertStringContainsString('Dodir 1: crop mora biti apsolutan', $problems);
        $this->assertStringContainsString('Dodir 2: stranica nije među stranicama letka', $problems);
        $this->assertStringContainsString('Dodir 3: isti naziv kao drugi dodir', $problems);
    }

    public function test_taps_go_forward_through_the_pages_never_back(): void
    {
        $demo = $this->demo();
        $demo['pages'] = [$demo['pages'][0], $demo['pages'][1], $demo['pages'][2]];
        $demo['taps'][0]['page'] = 6;
        $demo['taps'][1]['page'] = 5;
        $demo['taps'][2]['page'] = 6;

        $this->assertStringContainsString('Dodir 2: stranica je prije one s prethodnog dodira', implode(' ', CatalogDemo::problems($demo)));
    }

    public function test_it_takes_exactly_three_taps_and_at_least_one_page(): void
    {
        $two = $this->demo();
        array_pop($two['taps']);
        $this->assertSame(['Treba točno 3 proizvoda za dodir, ne 2.'], CatalogDemo::problems($two));

        $this->assertSame(['Nema stranica letka.'], CatalogDemo::problems([...$this->demo(), 'pages' => []]));
    }

    public function test_a_price_must_be_a_positive_whole_number_of_cents(): void
    {
        $demo = $this->demo();
        $demo['taps'][0]['price_cents'] = 3.79;

        $this->assertStringContainsString('cijena (price_cents) mora biti pozitivan cijeli broj centi', implode(' ', CatalogDemo::problems($demo)));
    }

    public function test_from_array_throws_with_the_reasons_in_the_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('raw.demo ne valja:');

        CatalogDemo::fromArray([...$this->demo(), 'total_cents' => 1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function demo(): array
    {
        $payload = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/catalog-feed-konzum-2026-10-07.json'), true, flags: JSON_THROW_ON_ERROR);

        return $payload['items'][0]['raw']['demo'];
    }
}
