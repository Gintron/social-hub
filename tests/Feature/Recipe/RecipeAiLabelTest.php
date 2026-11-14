<?php

declare(strict_types=1);

namespace Tests\Feature\Recipe;

use App\Actions\CreateDraft;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\Source;
use App\Publishing\Meta\InstagramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\MakesRecipeItems;
use Tests\TestCase;

/**
 * The picture of a dish is drawn by an AI, so Instagram's label is set whatever the format: on a single image's container and on a
 * carousel's parent (Instagram Platform changelog, 22 Jun 2026: "on the carousel container only"), not on its children.
 */
final class RecipeAiLabelTest extends TestCase
{
    use MakesRecipeItems;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.app_secret', 'app-secret');
        config()->set('hub.media_disk', 'public');
        Storage::fake('public');
        Sleep::fake();

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && str_ends_with($path, '/media_publish')) {
                return Http::response(['id' => 'media-1']);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/media')) {
                return Http::response(['id' => 'container-'.random_int(1000, 9999)]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($path, 'content_publishing_limit')) {
                return Http::response(['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 100, 'quota_duration' => 86400]]]]);
            }

            if (str_contains((string) ($query['fields'] ?? ''), 'status_code')) {
                return Http::response(['status_code' => 'FINISHED']);
            }

            return Http::response(['permalink' => 'https://www.instagram.com/p/XYZ/']);
        });
    }

    public function test_a_single_image_of_a_dish_is_labelled(): void
    {
        app(InstagramPublisher::class)->publish($this->variant(ContentFormat::Image, 1));

        $containers = $this->containers();

        $this->assertCount(1, $containers);
        $this->assertArrayHasKey('image_url', $containers[0]);
        $this->assertSame('true', $containers[0]['is_ai_generated']);
    }

    public function test_a_carousel_of_a_dish_is_labelled_on_its_parent_only(): void
    {
        app(InstagramPublisher::class)->publish($this->variant(ContentFormat::Carousel, 3));

        $containers = $this->containers();
        $children = array_values(array_filter($containers, fn (array $data): bool => ($data['is_carousel_item'] ?? null) === 'true'));
        $parent = array_values(array_filter($containers, fn (array $data): bool => ($data['media_type'] ?? null) === 'CAROUSEL'));

        $this->assertCount(3, $children);
        $this->assertCount(1, $parent);
        $this->assertSame('true', $parent[0]['is_ai_generated']);

        foreach ($children as $child) {
            $this->assertArrayNotHasKey('is_ai_generated', $child);
        }
    }

    public function test_a_post_that_is_nobodys_drawing_is_not_labelled(): void
    {
        app(InstagramPublisher::class)->publish($this->variant(ContentFormat::Image, 1, recipe: false));

        $this->assertArrayNotHasKey('is_ai_generated', $this->containers()[0]);
    }

    /**
     * @return list<array<string, mixed>> The data of every container request, in order.
     */
    private function containers(): array
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/media'))
            ->map(fn (array $pair): array => $pair[0]->data())
            ->values()
            ->all();
    }

    private function variant(ContentFormat $format, int $slides, bool $recipe = true): PostVariant
    {
        $brand = $this->recipeBrand();
        $item = $recipe
            ? $this->recipeItem($brand)
            : ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create(['title' => 'Konobar/ica']);
        $account = SocialAccount::factory()->for($brand)->create([
            'platform' => Platform::InstagramBusiness, 'access_token' => 'token', 'token_expires_at' => now()->addHours(20),
            'meta' => ['ig_user_id' => '1784'],
        ]);

        $variant = app(CreateDraft::class)->execute($item, [$account], render: false)->variants->first();

        foreach (range(1, $slides) as $position) {
            $asset = MediaAsset::factory()->for($brand)->create(['width' => 1080, 'height' => 1350, 'format' => 'jpg', 'path' => "media/slide-{$position}.jpg", 'bytes' => 200_000]);
            $variant->media()->attach($asset->id, ['position' => $position - 1]);
        }

        $variant->forceFill(['settings' => [...$variant->settings, 'format' => $format->value]])->save();

        return $variant->fresh(['account', 'media', 'draft']);
    }
}
