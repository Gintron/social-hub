<?php

declare(strict_types=1);

namespace Tests\Feature\Drafting;

use App\Drafting\DigestBuilder;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Jobs\RenderDigestJob;
use App\Jobs\RenderVideoJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

final class DigestBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_collects_the_highest_priority_items_into_one_carousel(): void
    {
        Queue::fake();

        $brand = $this->brand();
        $this->deals($brand, [['Jaja', 60], ['Mlijeko', 20], ['Kava', 45], ['Kruh', 5]]);
        $page = SocialAccount::factory()->for($brand)->create();
        $instagram = SocialAccount::factory()->for($brand)->instagram()->create();

        $draft = app(DigestBuilder::class)->build($brand, ContentKind::Deal, [$page, $instagram], count: 3);

        $this->assertSame(PostDraft::KIND_DIGEST, $draft->kind);
        $this->assertSame('Top 3 akcija ovog tjedna', $draft->title);
        $this->assertSame(['Jaja', 'Kava', 'Mlijeko'], $draft->contentItems->pluck('title')->all());
        $this->assertSame([0, 1, 2], $draft->contentItems->pluck('pivot.position')->all());
        $this->assertCount(2, $draft->variants);

        Queue::assertPushed(RenderDigestJob::class, fn (RenderDigestJob $job): bool => $job->draftId === $draft->id);
    }

    public function test_the_caption_numbers_the_slides_and_names_the_prices(): void
    {
        Queue::fake();

        $brand = $this->brand();
        $this->deals($brand, [['Jaja', 60], ['Kava', 45]]);
        $page = SocialAccount::factory()->for($brand)->create();
        $instagram = SocialAccount::factory()->for($brand)->instagram()->create();

        $draft = app(DigestBuilder::class)->build($brand, ContentKind::Deal, [$page, $instagram], count: 2);

        $facebook = $draft->variants->firstWhere('platform', Platform::FacebookPage)->caption;
        $this->assertStringContainsString('1. Jaja — 1,99 € (−60 %)', $facebook);
        $this->assertStringContainsString('2. Kava — 1,99 € (−45 %)', $facebook);
        $this->assertStringContainsString('https://uselisto.com', $facebook);

        $instagramCaption = $draft->variants->firstWhere('platform', Platform::InstagramBusiness)->caption;
        $this->assertStringContainsString('Link u biu', $instagramCaption);
        $this->assertStringNotContainsString('https://', $instagramCaption);
        $this->assertLessThanOrEqual(2200, mb_strlen($instagramCaption));
    }

    public function test_a_digest_can_narrow_to_one_tag_and_go_out_as_a_reel(): void
    {
        Queue::fake();

        $brand = $this->brand();
        $items = $this->deals($brand, [['Jaja', 60], ['Kava', 45], ['Mlijeko', 20]]);
        $items->firstWhere('title', 'Jaja')->forceFill(['tags' => ['lidl']])->save();
        $items->whereIn('title', ['Kava', 'Mlijeko'])->each(fn (ContentItem $item) => $item->forceFill(['tags' => ['kaufland', 'dukat']])->save());
        $page = SocialAccount::factory()->for($brand)->create();
        $tiktok = SocialAccount::factory()->for($brand)->create(['platform' => Platform::TikTok]);

        $draft = app(DigestBuilder::class)->build(
            $brand,
            ContentKind::Deal,
            [$page, $tiktok],
            headline: 'Top {count} akcija u Kauflandu',
            tag: 'kaufland',
            formats: [Platform::FacebookPage->value => ContentFormat::Video],
        );

        $this->assertSame('Top 2 akcija u Kauflandu', $draft->title);
        $this->assertSame(['Kava', 'Mlijeko'], $draft->contentItems->pluck('title')->all());
        $this->assertSame(ContentFormat::Video, $draft->variants->firstWhere('platform', Platform::FacebookPage)->format());
        $this->assertSame(ContentFormat::Carousel, $draft->variants->firstWhere('platform', Platform::TikTok)->format());

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job): bool => $job->draftId === $draft->id);
        Queue::assertPushed(RenderDigestJob::class, fn (RenderDigestJob $job): bool => $job->draftId === $draft->id);
    }

    public function test_it_refuses_to_build_a_carousel_out_of_one_item(): void
    {
        Queue::fake();

        $brand = $this->brand();
        $this->deals($brand, [['Jaja', 60]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/barem 2/');

        app(DigestBuilder::class)->build($brand, ContentKind::Deal, [SocialAccount::factory()->for($brand)->create()]);
    }

    public function test_expired_and_already_drafted_items_are_left_out(): void
    {
        Queue::fake();

        $brand = $this->brand();
        $items = $this->deals($brand, [['Svjeza', 60], ['Istekla', 90], ['Vec objavljena', 80], ['Druga', 30]]);

        $items->firstWhere('title', 'Istekla')->forceFill(['expires_at' => now()->subDay()])->save();

        $used = $items->firstWhere('title', 'Vec objavljena');
        PostDraft::factory()->create(['brand_id' => $brand->id])->contentItems()->attach($used->id, ['position' => 0]);

        $picked = app(DigestBuilder::class)->pick($brand, ContentKind::Deal, 10);

        $this->assertSame(['Svjeza', 'Druga'], $picked->pluck('title')->all());
    }

    public function test_the_command_builds_a_digest(): void
    {
        Queue::fake();

        $brand = $this->brand();
        $this->deals($brand, [['Jaja', 60], ['Kava', 45], ['Mlijeko', 20]]);
        SocialAccount::factory()->for($brand)->create();

        $this->artisan('hub:draft-digest', ['brand' => $brand->slug, '--kind' => 'deal', '--count' => 3, '--format' => 'video'])
            ->expectsOutputToContain('Top 3 akcija ovog tjedna')
            ->expectsOutputToContain('(video)')
            ->assertSuccessful();

        $this->assertSame(1, PostDraft::query()->where('kind', PostDraft::KIND_DIGEST)->count());

        $this->artisan('hub:draft-digest', ['brand' => $brand->slug, '--format' => 'image'])
            ->expectsOutputToContain('carousel or video')
            ->assertFailed();
    }

    public function test_the_command_explains_itself_when_a_brand_has_no_channels(): void
    {
        $brand = $this->brand();
        $this->deals($brand, [['Jaja', 60], ['Kava', 45]]);

        $this->artisan('hub:draft-digest', ['brand' => $brand->slug])
            ->expectsOutputToContain('no active accounts')
            ->assertFailed();
    }

    public function test_a_roundup_takes_at_most_two_of_one_brand_and_fills_up_only_when_it_must(): void
    {
        $brand = $this->brand();
        $deals = $this->deals($brand, [
            ['Gorenje robot', 90], ['Gorenje blender', 89], ['Gorenje mikser', 88], ['Gorenje toster', 87],
            ['K-Classic pizza', 60], ['K-Classic juha', 59], ['K-Classic tjestenina', 58],
            ['Vanish', 40],
        ]);
        $deals->each(fn (ContentItem $item) => $item->forceFill(['tags' => ['kaufland', mb_strtolower(explode(' ', $item->title)[0])]])->save());

        $this->assertSame(
            ['Gorenje robot', 'Gorenje blender', 'K-Classic pizza', 'K-Classic juha', 'Vanish'],
            app(DigestBuilder::class)->pick($brand, ContentKind::Deal, 5, tag: 'kaufland')->pluck('title')->all(),
            'oznaka serije se ne broji, marka najviše dvaput',
        );

        // Seven: diversity gives five, the next best by priority fill up (Gorenje before the third
        // K-Classic), and the roundup still reads from the best deal down.
        $this->assertSame(
            ['Gorenje robot', 'Gorenje blender', 'Gorenje mikser', 'Gorenje toster', 'K-Classic pizza', 'K-Classic juha', 'Vanish'],
            app(DigestBuilder::class)->pick($brand, ContentKind::Deal, 7, tag: 'kaufland')->pluck('title')->all(),
        );
    }

    private function brand(): Brand
    {
        return Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com']);
    }

    /**
     * @param  list<array{0: string, 1: int}>  $deals  title and discount percentage
     * @return Collection<int, ContentItem>
     */
    private function deals(Brand $brand, array $deals): Collection
    {
        $source = Source::factory()->for($brand)->create();

        return new Collection(array_map(fn (array $deal): ContentItem => ContentItem::factory()->for($source)->for($brand)->create([
            'kind' => ContentKind::Deal,
            'title' => $deal[0],
            'subtitle' => 'Konzum',
            'priority' => $deal[1],
            'price' => ['current_cents' => 199, 'old_cents' => 499, 'discount_pct' => $deal[1], 'currency' => 'EUR', 'unit_label' => null],
        ]), $deals));
    }
}
