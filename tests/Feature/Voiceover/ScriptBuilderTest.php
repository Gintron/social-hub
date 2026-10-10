<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\Source;
use App\Rendering\Scene;
use App\Rendering\ScenePlanner;
use App\Voiceover\Narrator;
use App\Voiceover\ScriptBuilder;
use App\Voiceover\ScriptGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a video says is written from the item's own fields, slide by slide, and every figure in it is
 * one the item states.
 */
final class ScriptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deal_says_what_it_is_and_costs_then_what_the_hook_left_out(): void
    {
        $draft = $this->draft($this->deal(), brand: $this->listo());

        $script = $this->script($draft);

        $this->assertSame([
            'Kruh bijeli 500 g u trgovini Konzum za 1,49 €.',
            'Popust 25 %. Vrijedi do 30.09.2026.',
            'Preuzmi Listo. Dodaj prvi proizvod s letka.',
        ], $script->texts());
        $this->assertSame([Scene::HOOK, Scene::CARD, Scene::CLOSING], array_map(fn ($line): string => $line->role, $script->lines));
    }

    public function test_a_job_says_the_pay_under_its_own_label_and_the_place(): void
    {
        $draft = $this->draft($this->job(), brand: $this->students());

        $script = $this->script($draft);

        $this->assertSame([
            'Konobar/ica. Satnica: 7.00 - 8.00 €/H. Lokacija: Split.',
            'Poslodavac: Hotel Adriatic d.o.o. Sezonski posao, smještaj i obrok.',
            'Prijavi se na studentski-poslovi.hr.',
        ], $script->texts());
    }

    public function test_a_comparison_names_the_cheapest_shop_and_then_the_next_two(): void
    {
        $item = $this->item(ContentKind::Comparison, [
            'title' => 'Mljevena kava 500 g',
            'subtitle' => null,
            'price' => null,
            'facts' => [
                ['label' => 'Lidl', 'value' => '9,98 €/kg'],
                ['label' => 'Kaufland', 'value' => '11,98 €/kg'],
                ['label' => 'Konzum', 'value' => '12,49 €/kg'],
                ['label' => 'Spar', 'value' => '13,20 €/kg'],
            ],
            'badges' => [],
        ]);

        $script = $this->script($this->draft($item, brand: $this->listo()));

        $this->assertSame('Mljevena kava 500 g. Najjeftinije: Lidl, 9,98 €/kg.', $script->lines[0]->text);
        $this->assertSame('Zatim Kaufland 11,98 €/kg, Konzum 12,49 €/kg.', $script->lines[1]->text);
    }

    public function test_a_comparison_question_answers_without_repeating_najjeftinije(): void
    {
        $item = $this->item(ContentKind::Comparison, [
            'title' => 'Gdje je mljeveno meso ovaj tjedan najjeftinije?',
            'subtitle' => null,
            'price' => null,
            'facts' => [
                ['label' => 'Spar', 'value' => '6,42 €/kg'],
                ['label' => 'Kaufland', 'value' => '6,43 €/kg'],
                ['label' => 'Konzum', 'value' => '6,81 €/kg'],
            ],
            'badges' => [],
        ]);
        $draft = $this->draft($item, brand: $this->listo());

        $script = $this->script($draft);

        $this->assertSame('Gdje je mljeveno meso ovaj tjedan najjeftinije? U trgovini Spar, 6,42 €/kg.', $script->lines[0]->text);
        $this->assertSame('Zatim Kaufland 6,43 €/kg, Konzum 6,81 €/kg.', $script->lines[1]->text);
        $this->assertSame([], app(ScriptGuard::class)->check($script, $draft->contentItems));
    }

    public function test_a_comparison_recognizes_najjeftinije_regardless_of_case_and_punctuation(): void
    {
        foreach ([
            'Mljeveno meso: NAJJEFTINIJE!' => 'Mljeveno meso: NAJJEFTINIJE! U trgovini Spar, 6,42 €/kg.',
            'Najjeftinije, mljeveno meso' => 'Najjeftinije, mljeveno meso. U trgovini Spar, 6,42 €/kg.',
            'Meso po najjeftinijem izboru' => 'Meso po najjeftinijem izboru. Najjeftinije: Spar, 6,42 €/kg.',
        ] as $title => $expected) {
            $item = $this->item(ContentKind::Comparison, [
                'title' => $title,
                'subtitle' => null,
                'price' => null,
                'facts' => [['label' => 'Spar', 'value' => '6,42 €/kg']],
                'badges' => [],
            ]);

            $script = $this->script($this->draft($item, brand: $this->listo()));

            $this->assertSame($expected, $script->lines[0]->text);
        }
    }

    public function test_a_comparison_question_without_prices_keeps_only_its_title(): void
    {
        $item = $this->item(ContentKind::Comparison, [
            'title' => 'Gdje je mljeveno meso ovaj tjedan najjeftinije?',
            'subtitle' => null,
            'price' => null,
            'facts' => [],
            'badges' => [],
        ]);

        $script = $this->script($this->draft($item, brand: $this->listo()));

        $this->assertSame($item->title, $script->lines[0]->text);
        $this->assertSame('', $script->lines[1]->text);
    }

    public function test_a_roundup_comparison_also_answers_without_repeating_najjeftinije(): void
    {
        $item = $this->item(ContentKind::Comparison, [
            'title' => 'Gdje je mljeveno meso ovaj tjedan najjeftinije?',
            'subtitle' => null,
            'price' => null,
            'facts' => [['label' => 'Spar', 'value' => '6,42 €/kg']],
            'badges' => [],
        ]);
        $draft = $this->draft($item, brand: $this->listo(), digest: true, title: 'Usporedba cijena');

        $script = $this->script($draft);

        $this->assertSame('Usporedba cijena.', $script->lines[0]->text);
        $this->assertSame('Gdje je mljeveno meso ovaj tjedan najjeftinije? U trgovini Spar, 6,42 €/kg.', $script->lines[1]->text);
        $this->assertSame([], app(ScriptGuard::class)->check($script, $draft->contentItems));
    }

    public function test_a_roundup_of_one_shop_names_it_once_on_the_cover(): void
    {
        $draft = $this->draft([$this->deal('Kruh bijeli 500 g', 149), $this->deal('Mlijeko 1 l', 99, 40)], digest: true, brand: $this->listo(), title: 'Top 2 akcije u Konzumu');

        $script = $this->script($draft);

        $this->assertSame([
            'Top 2 akcije u Konzumu. Popusti do 40 %.',
            'Kruh bijeli 500 g za 1,49 €. Popust 25 %.',
            'Mlijeko 1 l za 0,99 €. Popust 40 %.',
            'Preuzmi Listo. Dodaj prvi proizvod s letka.',
        ], $script->texts());
    }

    public function test_a_roundup_of_several_shops_says_whose_each_offer_is(): void
    {
        $draft = $this->draft([$this->deal('Kruh bijeli 500 g', 149, subtitle: 'Konzum'), $this->deal('Mlijeko 1 l', 99, subtitle: 'Lidl')], digest: true, brand: $this->listo(), title: 'Dvije akcije');

        $this->assertSame('Kruh bijeli 500 g u trgovini Konzum za 1,49 €. Popust 25 %.', $this->script($draft)->lines[1]->text);
        $this->assertSame('Mlijeko 1 l u trgovini Lidl za 0,99 €. Popust 25 %.', $this->script($draft)->lines[2]->text);
    }

    public function test_a_roundup_of_jobs_reads_each_job_like_a_hook(): void
    {
        $draft = $this->draft([$this->job(), $this->job('Kuhar/ica', 'KARLOVAC', '6.50 €/H')], digest: true, brand: $this->students(), title: 'Top 2 posla');

        $script = $this->script($draft);

        $this->assertSame('Top 2 posla.', $script->lines[0]->text, 'jobs have no discount to add to the cover');
        $this->assertSame('Konobar/ica. Satnica: 7.00 - 8.00 €/H. Lokacija: Split.', $script->lines[1]->text);
        $this->assertSame('Kuhar/ica. Satnica: 6.50 €/H. Lokacija: Karlovac.', $script->lines[2]->text);
    }

    public function test_a_deal_without_a_price_or_a_shop_still_gets_a_line(): void
    {
        $item = $this->item(ContentKind::Deal, ['title' => 'Akcija na sve', 'subtitle' => null, 'price' => null, 'facts' => [], 'badges' => [], 'expires_at' => null]);

        $script = $this->script($this->draft($item, brand: $this->listo()));

        $this->assertSame('Akcija na sve.', $script->lines[0]->text);
        $this->assertSame('', $script->lines[1]->text, 'nothing to add, so the card is left to the music');
    }

    public function test_a_brand_without_a_call_to_action_says_nothing_at_the_end(): void
    {
        $brand = Brand::factory()->create(['voice' => []]);

        $script = $this->script($this->draft($this->deal(), brand: $brand));

        $this->assertSame('', $script->lines[2]->text);
    }

    public function test_the_brands_own_closing_line_wins(): void
    {
        $brand = $this->listo(['outro' => 'Preuzmi Listo besplatno i složi prvu listu.']);

        $script = $this->script($this->draft($this->deal(), brand: $brand));

        $this->assertSame('Preuzmi Listo besplatno i složi prvu listu.', $script->lines[2]->text);
    }

    public function test_a_long_title_is_cut_at_a_word_and_a_cut_never_ends_on_a_preposition(): void
    {
        $long = 'Coca-Cola Original Taste bezalkoholno gazirano piće u plastičnoj boci s okusom kole i dodatkom vitamina 2 l';
        $script = $this->script($this->draft($this->deal($long, 149), brand: $this->listo()));

        // Sixty characters end inside "plastičnoj"; the cut goes back to "u", which would hang, and past it.
        $this->assertSame('Coca-Cola Original Taste bezalkoholno gazirano piće u trgovini Konzum za 1,49 €.', $script->lines[0]->text);

        // The limit falls exactly at the end of "bez": the word stays out of the cut.
        $exact = $this->script($this->draft($this->deal('Kukuruzne pahuljice s medom i orasima bez dodanog šećera bez glutena u kutiji od 375 g', 149), brand: $this->listo(['slug' => 'other'])));
        $this->assertSame('Kukuruzne pahuljice s medom i orasima bez dodanog šećera u trgovini Konzum za 1,49 €.', $exact->lines[0]->text);
        $this->assertStringNotContainsString('šećera bez', $exact->lines[0]->text);

        // A title that fits is not touched.
        $fits = $this->script($this->draft($this->deal('Mlijeko 3,2% m.m. 1 l', 99), brand: $this->listo(['slug' => 'third'])));
        $this->assertStringStartsWith('Mlijeko 3,2% m.m. 1 l u trgovini', $fits->lines[0]->text);
    }

    public function test_a_line_that_runs_long_loses_its_last_sentences_but_keeps_the_first(): void
    {
        $item = $this->item(ContentKind::Generic, [
            'title' => 'Otvoreni dan Fakulteta strojarstva i brodogradnje',
            'subtitle' => null,
            'price' => null,
            'badges' => [],
            'facts' => [
                ['label' => 'GDJE', 'value' => 'Dvorana A u glavnoj zgradi na Savskoj cesti, drugi kat'],
                ['label' => 'KAD', 'value' => 'U subotu od deset ujutro do dva poslijepodne, ulaz slobodan'],
            ],
        ]);

        $line = $this->script($this->draft($item, brand: $this->listo()))->lines[0]->text;

        // Title, then two facts would be 160 characters; the second fact does not fit and is dropped whole.
        $this->assertSame('Otvoreni dan Fakulteta strojarstva i brodogradnje. Dvorana A u glavnoj zgradi na Savskoj cesti, drugi kat.', $line);
        $this->assertLessThanOrEqual(130, mb_strlen($line));
    }

    public function test_everything_the_builder_writes_passes_the_amount_check(): void
    {
        $guard = app(ScriptGuard::class);

        foreach ([
            $this->draft($this->deal(), brand: $this->listo()),
            $this->draft($this->job(), brand: $this->students()),
            $this->draft([$this->deal('Kruh', 149), $this->deal('Mlijeko', 99, 40)], digest: true, brand: $this->listo(), title: 'Top 2 akcije'),
        ] as $draft) {
            $this->assertSame([], $guard->check($this->script($draft), $draft->contentItems));
        }
    }

    public function test_a_script_somebody_wrote_is_aligned_to_the_slides(): void
    {
        $draft = $this->draft($this->deal(), brand: $this->listo());
        $scenes = app(ScenePlanner::class)->forDraft($draft);

        $script = app(ScriptBuilder::class)->fromLines($scenes, ['Prva.', '  Druga.  ']);

        $this->assertSame(['Prva.', 'Druga.', ''], $script->texts(), 'a missing line is a slide left to the music');
        $this->assertSame([Scene::HOOK, Scene::CARD, Scene::CLOSING], array_map(fn ($line): string => $line->role, $script->lines));
        $this->assertCount(3, app(ScriptBuilder::class)->fromLines($scenes, ['a', 'b', 'c', 'd'])->lines, 'lines beyond the slides are dropped');
    }

    public function test_the_script_can_be_read_without_speaking_it(): void
    {
        $draft = $this->draft($this->deal(), brand: $this->listo());

        $script = app(Narrator::class)->script($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertSame('Kruh bijeli 500 g u trgovini Konzum za 1,49 €.', $script->lines[0]->text);
    }

    private function script(PostDraft $draft): \App\Voiceover\Script
    {
        return app(ScriptBuilder::class)->build(
            $draft->brand,
            app(ScenePlanner::class)->forDraft($draft),
            $draft->contentItems,
            $draft->title,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function listo(array $extra = []): Brand
    {
        return Brand::factory()->create([
            'slug' => $extra['slug'] ?? 'uselisto-'.Brand::query()->count(),
            'name' => 'Listo',
            'voice' => ['cta' => 'Preuzmi Listo', 'activation' => 'Dodaj prvi proizvod s letka.', 'pitch' => 'Letak → tvoja lista.'],
            'voiceover' => array_diff_key($extra, ['slug' => 1]) === [] ? null : ['enabled' => true, 'voice_id' => 'v1', ...array_diff_key($extra, ['slug' => 1])],
        ]);
    }

    private function students(): Brand
    {
        return Brand::factory()->create([
            'slug' => 'studentski-poslovi',
            'name' => 'Studentski poslovi',
            'voice' => ['cta' => 'Prijavi se na studentski-poslovi.hr'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function item(ContentKind $kind, array $overrides = []): ContentItem
    {
        return ContentItem::factory()->for(Source::factory())->create(['kind' => $kind, ...$overrides]);
    }

    private function deal(string $title = 'Kruh bijeli 500 g', int $cents = 149, int $discount = 25, string $subtitle = 'Konzum'): ContentItem
    {
        return $this->item(ContentKind::Deal, [
            'title' => $title,
            'subtitle' => $subtitle,
            'price' => ['current_cents' => $cents, 'old_cents' => (int) round($cents / (1 - $discount / 100)), 'discount_pct' => $discount, 'currency' => 'EUR', 'unit_label' => null],
            'facts' => [['label' => 'NAJNIŽA U 30 DANA', 'value' => number_format(($cents + 40) / 100, 2, ',', '').' €']],
            'badges' => ["−{$discount} %"],
            'expires_at' => '2026-09-30T23:59:59Z',
        ]);
    }

    private function job(string $title = 'Konobar/ica', string $location = 'SPLIT', string $pay = '7.00 - 8.00 €/H'): ContentItem
    {
        return $this->item(ContentKind::Job, [
            'title' => $title,
            'subtitle' => 'Hotel Adriatic d.o.o.',
            'price' => null,
            'facts' => [['label' => 'LOKACIJA', 'value' => $location], ['label' => 'SATNICA', 'value' => $pay]],
            'badges' => ['Sezonski posao', 'Smještaj', 'Obrok'],
        ]);
    }

    /**
     * @param  ContentItem|list<ContentItem>  $items
     */
    private function draft(ContentItem|array $items, ?Brand $brand = null, bool $digest = false, ?string $title = null): PostDraft
    {
        $items = is_array($items) ? $items : [$items];
        $brand ??= Brand::factory()->create();

        $draft = PostDraft::factory()->create([
            'brand_id' => $brand->id,
            'kind' => $digest ? PostDraft::KIND_DIGEST : PostDraft::KIND_SINGLE,
            'title' => $title ?? $items[0]->title,
        ]);

        foreach ($items as $position => $item) {
            $item->forceFill(['brand_id' => $brand->id])->save();
            $draft->contentItems()->attach($item->id, ['position' => $position, 'checksum' => $item->checksum]);
        }

        return $draft->load(['brand', 'contentItems']);
    }
}
