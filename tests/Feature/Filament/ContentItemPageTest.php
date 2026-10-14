<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Actions\CreateDraft;
use App\Enums\ContentKind;
use App\Filament\Resources\ContentItems\Pages\ViewContentItem;
use App\Filament\Resources\ContentItems\RelationManagers\PostDraftsRelationManager;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The page of one item: what a post is made from, said the way the post will say it, and what became of it.
 */
final class ContentItemPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.admin_emails', ['ops@example.test']);
        $this->actingAs(User::factory()->create(['email' => 'ops@example.test']));
    }

    public function test_the_price_is_shown_as_the_slide_and_the_caption_say_it(): void
    {
        $job = $this->item(['title' => 'Konobar/ica']);
        $deal = $this->item([
            'title' => 'Kruh',
            'kind' => ContentKind::Deal,
            'facts' => [],
            'price' => ['current_cents' => 149, 'old_cents' => 199, 'discount_pct' => 25, 'currency' => 'EUR', 'unit_label' => null],
        ]);

        // A job's own wording ("7.00 €/H") wins over the number, and is named.
        Livewire::test(ViewContentItem::class, ['record' => $job->getRouteKey()])
            ->assertOk()
            ->assertSee('SATNICA: 7.00 €/H');

        Livewire::test(ViewContentItem::class, ['record' => $deal->getRouteKey()])
            ->assertOk()
            ->assertSee('1,49 € (prije 1,99 €) · −25 %');
    }

    public function test_an_item_without_a_price_or_facts_has_no_empty_price_section(): void
    {
        $item = $this->item(['title' => 'Članak o štednji', 'kind' => ContentKind::Article, 'price' => null, 'facts' => []]);

        Livewire::test(ViewContentItem::class, ['record' => $item->getRouteKey()])
            ->assertOk()
            ->assertDontSee('Cijena i činjenice');
    }

    public function test_the_page_is_titled_with_the_item_and_says_whose_it_is(): void
    {
        $item = $this->item(['title' => 'Konobar/ica']);

        $page = Livewire::test(ViewContentItem::class, ['record' => $item->getRouteKey()]);

        $page->assertSee('Konobar/ica')->assertSee($item->brand->name)->assertSee(ContentKind::Job->label());
        $this->assertSame('Konobar/ica', $page->instance()->getTitle());
    }

    public function test_the_item_lists_the_posts_made_from_it(): void
    {
        $item = $this->item(['title' => 'Konobar/ica']);
        $other = $this->item(['title' => 'Vozač'], $item->brand);
        $account = SocialAccount::factory()->for($item->brand)->create();

        $draft = app(CreateDraft::class)->execute($item, [$account], render: false);
        $elsewhere = app(CreateDraft::class)->execute($other, [$account], render: false);

        Livewire::test(PostDraftsRelationManager::class, ['ownerRecord' => $item, 'pageClass' => ViewContentItem::class])
            ->assertOk()
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$elsewhere]);
    }

    public function test_an_item_nobody_posted_says_so(): void
    {
        $item = $this->item(['title' => 'Konobar/ica']);

        Livewire::test(PostDraftsRelationManager::class, ['ownerRecord' => $item, 'pageClass' => ViewContentItem::class])
            ->assertOk()
            ->assertSee('Iz ove stavke još nije napravljena objava');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(array $attributes = [], ?Brand $brand = null): ContentItem
    {
        $brand ??= Brand::factory()->create();

        return ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create($attributes);
    }
}
