<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\Source;
use App\Rendering\Scene;
use App\Rendering\ScenePlanner;
use App\Rendering\TemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The slides of a video, planned before anything is rendered: what they are and what each is for.
 */
final class ScenePlannerTest extends TestCase
{
    use RefreshDatabase;

    private ?Brand $brand = null;

    public function test_one_job_is_told_as_hook_card_and_call_to_action(): void
    {
        $draft = $this->draft([$this->item(ContentKind::Job)]);

        $scenes = app(ScenePlanner::class)->forDraft($draft);

        $this->assertSame(['kinds/job-hook-story', 'kinds/job-story', 'kinds/job-cta-story'], array_map(fn (Scene $scene): string => $scene->templateKey, $scenes));
        $this->assertSame([Scene::HOOK, Scene::CARD, Scene::CLOSING], array_map(fn (Scene $scene): string => $scene->role, $scenes));
        $this->assertSame($draft->contentItems->first()->id, $scenes[0]->item?->id);
        $this->assertSame($draft->contentItems->first()->id, $scenes[2]->item?->id, 'the end card of a single item still knows the item it follows');
    }

    public function test_one_deal_and_one_comparison_use_their_own_hooks(): void
    {
        $deal = app(ScenePlanner::class)->forDraft($this->draft([$this->item(ContentKind::Deal)]));
        $comparison = app(ScenePlanner::class)->forDraft($this->draft([$this->item(ContentKind::Comparison)]));

        $this->assertSame(['kinds/deal-hook-story', 'kinds/deal-story', 'kinds/cta-story'], array_map(fn (Scene $scene): string => $scene->templateKey, $deal));
        $this->assertSame([Scene::HOOK, Scene::CARD, Scene::CLOSING], array_map(fn (Scene $scene): string => $scene->role, $deal));
        $this->assertSame(['kinds/comparison-hook-story', 'kinds/comparison-story', 'kinds/cta-story'], array_map(fn (Scene $scene): string => $scene->templateKey, $comparison));
        $this->assertSame([Scene::HOOK, Scene::CARD, Scene::CLOSING], array_map(fn (Scene $scene): string => $scene->role, $comparison));
    }

    public function test_a_digest_opens_with_its_cover_gives_each_item_a_card_and_closes_with_the_end_card(): void
    {
        $draft = $this->draft([$this->item(ContentKind::Deal), $this->item(ContentKind::Deal)], digest: true);

        $scenes = app(ScenePlanner::class)->forDraft($draft);

        $this->assertSame(['kinds/digest-cover-story', 'kinds/deal-story', 'kinds/deal-story', 'kinds/cta-story'], array_map(fn (Scene $scene): string => $scene->templateKey, $scenes));
        $this->assertSame([Scene::COVER, Scene::CARD, Scene::CARD, Scene::CLOSING], array_map(fn (Scene $scene): string => $scene->role, $scenes));
        $this->assertNull($scenes[0]->item, 'a cover speaks for the whole roundup');
        $this->assertSame($draft->contentItems[1]->id, $scenes[2]->item?->id);
        $this->assertNull($scenes[3]->item);
    }

    public function test_a_roundup_of_jobs_has_its_own_cover_and_end_card(): void
    {
        $draft = $this->draft([$this->item(ContentKind::Job), $this->item(ContentKind::Job)], digest: true);

        $scenes = app(ScenePlanner::class)->forDraft($draft);

        $this->assertSame(['kinds/job-digest-cover-story', 'kinds/job-story', 'kinds/job-story', 'kinds/job-cta-story'], array_map(fn (Scene $scene): string => $scene->templateKey, $scenes));
    }

    public function test_an_explicit_template_is_one_slide_per_item(): void
    {
        $draft = $this->draft([$this->item(ContentKind::Deal), $this->item(ContentKind::Deal)], digest: true);

        $scenes = app(ScenePlanner::class)->forDraft($draft, 'kinds/deal-story');

        $this->assertCount(2, $scenes);
        $this->assertSame(['kinds/deal-story', 'kinds/deal-story'], array_map(fn (Scene $scene): string => $scene->templateKey, $scenes));
        $this->assertSame([Scene::CARD, Scene::CARD], array_map(fn (Scene $scene): string => $scene->role, $scenes));
    }

    public function test_every_template_has_a_role(): void
    {
        $registry = app(TemplateRegistry::class);

        $this->assertSame(Scene::COVER, $registry->roleOf('kinds/digest-cover-story'));
        $this->assertSame(Scene::COVER, $registry->roleOf('kinds/job-digest-cover-portrait'));
        $this->assertSame(Scene::HOOK, $registry->roleOf('kinds/hook-story'));
        $this->assertSame(Scene::HOOK, $registry->roleOf('kinds/deal-hook-story'));
        $this->assertSame(Scene::HOOK, $registry->roleOf('kinds/job-hook-story'));
        $this->assertSame(Scene::HOOK, $registry->roleOf('kinds/comparison-hook-story'));
        $this->assertSame(Scene::CLOSING, $registry->roleOf('kinds/cta-story'));
        $this->assertSame(Scene::CLOSING, $registry->roleOf('kinds/job-cta-story'));
        $this->assertSame(Scene::CARD, $registry->roleOf('kinds/deal-story'));
        $this->assertSame(Scene::CARD, $registry->roleOf('kinds/feature-story'));
    }

    private function item(ContentKind $kind): ContentItem
    {
        return ContentItem::factory()->for(Source::factory()->for($this->brand()))->for($this->brand())->create(['kind' => $kind]);
    }

    private function brand(): Brand
    {
        return $this->brand ??= Brand::factory()->create();
    }

    /**
     * @param  list<ContentItem>  $items
     */
    private function draft(array $items, bool $digest = false): PostDraft
    {
        $draft = PostDraft::factory()->create([
            'brand_id' => $this->brand()->id,
            'kind' => $digest ? PostDraft::KIND_DIGEST : PostDraft::KIND_SINGLE,
        ]);

        foreach ($items as $position => $item) {
            $draft->contentItems()->attach($item->id, ['position' => $position, 'checksum' => $item->checksum]);
        }

        return $draft->load('contentItems');
    }
}
