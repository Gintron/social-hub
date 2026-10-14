<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Actions\ApproveDraft;
use App\Actions\CreateDraft;
use App\Actions\DispatchDraftPublishing;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Publishing\PublishProblem;
use App\Publishing\RestrictedGoodsCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A channel that does not take alcohol gets no post that shows or names it, whoever made the draft.
 */
final class RestrictedGoodsPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['example.test/*' => Http::response('', 200)]);
    }

    public function test_a_channel_that_does_not_take_alcohol_skips_an_item_that_names_it(): void
    {
        [$brand, $item] = $this->brandWithItem(['title' => 'Ožujsko pivo 0,5 l']);

        $variant = $this->dispatch($item, SocialAccount::factory()->for($brand)->create());

        $this->assertSame(VariantStatus::Skipped, $variant->status);
        $this->assertSame('restricted_goods', $variant->error_code);
        $this->assertStringContainsString("Stavka 'Ožujsko pivo 0,5 l' sadrži alkohol", (string) $variant->error_message);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_the_tiktok_variant_is_skipped_the_same_way(): void
    {
        [$brand, $item] = $this->brandWithItem(['title' => 'Bijelo vino Graševina 1 l']);

        $variant = $this->dispatch($item, SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]));

        $this->assertSame(VariantStatus::Skipped, $variant->status);
        $this->assertSame('restricted_goods', $variant->error_code);
        $this->assertStringContainsString('TikTok ga ne objavljuje', (string) $variant->error_message);
    }

    public function test_the_source_flag_blocks_an_item_whose_title_says_nothing(): void
    {
        // A leaflet: the wine is on one of its pages, which only the source knows about.
        [$brand, $item] = $this->brandWithItem(['title' => 'Konzum katalog 13.10 – 19.10', 'restricted' => ['alcohol']]);

        $variant = $this->dispatch($item, SocialAccount::factory()->for($brand)->create());

        $this->assertSame('restricted_goods', $variant->error_code);
    }

    public function test_a_caption_that_names_a_drink_is_skipped_although_no_item_does(): void
    {
        [$brand, $item] = $this->brandWithItem();

        $variant = $this->dispatch($item, SocialAccount::factory()->for($brand)->create(), caption: 'Ljetna ponuda uz vino i sir');

        $this->assertSame('restricted_goods', $variant->error_code);
        $this->assertStringContainsString('Tekst objave spominje alkohol', (string) $variant->error_message);
    }

    public function test_an_item_that_names_no_drink_is_not_held_back(): void
    {
        [$brand, $item] = $this->brandWithItem(['title' => 'Konobar/ica']);
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create()], render: false);

        $this->assertNull(app(RestrictedGoodsCheck::class)->variantProblem($draft->variants()->firstOrFail()));
    }

    public function test_a_facebook_group_is_held_back_before_a_person_posts_it(): void
    {
        [$brand, $item] = $this->brandWithItem(['title' => 'Ožujsko pivo 0,5 l']);

        $variant = $this->dispatch($item, SocialAccount::factory()->for($brand)->group()->create());

        $this->assertSame(VariantStatus::Skipped, $variant->status, 'ručni kanal ne smije dobiti sadržaj s alkoholom');
        $this->assertSame('restricted_goods', $variant->error_code);
    }

    public function test_a_draft_whose_item_changed_after_it_was_made_is_not_posted(): void
    {
        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->create()], render: false);
        app(ApproveDraft::class)->execute($draft, null);

        // The source changed the item (a page moved, a price): the caption and the media still show the old one.
        $item->forceFill(['title' => 'Konobar/ica, nova cijena', 'checksum' => 'changed'])->save();

        app(DispatchDraftPublishing::class)->execute($draft->refresh());

        $variant = $draft->variants()->firstOrFail()->refresh();
        $this->assertSame(VariantStatus::Skipped, $variant->status);
        $this->assertSame('stale_content', $variant->error_code);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_a_manual_channel_shows_the_words_problem_but_no_media_problem(): void
    {
        [$brand, $item] = $this->brandWithItem();
        $draft = app(CreateDraft::class)->execute($item, [SocialAccount::factory()->for($brand)->group()->create()], render: false);
        $draft->variants()->firstOrFail()->forceFill(['caption' => 'Ljetna ponuda uz vino'])->save();

        $problem = app(PublishProblem::class)->of($draft->variants()->firstOrFail()->refresh());

        $this->assertNotNull($problem);
        $this->assertStringContainsString('Tekst objave spominje alkohol', $problem);
    }

    private function dispatch(ContentItem $item, SocialAccount $account, ?string $caption = null): PostVariant
    {
        $draft = app(CreateDraft::class)->execute($item, [$account], render: false);

        if ($caption !== null) {
            $draft->variants()->firstOrFail()->forceFill(['caption' => $caption])->save();
        }

        app(ApproveDraft::class)->execute($draft, null);
        app(DispatchDraftPublishing::class)->execute($draft->refresh());

        return $draft->variants()->firstOrFail()->refresh();
    }

    /**
     * @param  array<string, mixed>  $itemOverrides
     * @return array{0: Brand, 1: ContentItem}
     */
    private function brandWithItem(array $itemOverrides = []): array
    {
        $brand = Brand::factory()->create(['slug' => 'studentski-poslovi']);
        $source = Source::factory()->for($brand)->create();
        $item = ContentItem::factory()->for($source)->for($brand)->create(array_replace(['title' => 'Konobar/ica'], $itemOverrides));

        return [$brand, $item];
    }
}
