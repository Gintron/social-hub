<?php

declare(strict_types=1);

namespace Tests\Feature\Recipe;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Rendering\ImageRenderer;
use App\Rendering\ScenePlanner;
use App\Rendering\TemplateData;
use App\Rendering\TemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\MakesRecipeItems;
use Tests\TestCase;

/**
 * The slides of a dish: what they print, in which language, and what they never print. Rendered as HTML (the pictures are faked);
 * what Chromium makes of the HTML is looked at in the samples (`hub:recipe-draft`), not asserted here.
 */
final class RecipeFramesTest extends TestCase
{
    use MakesRecipeItems;
    use RefreshDatabase;

    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAwAAAAMCAIAAADZF8uwAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAF0lEQVQYlWN8zMDPQAgwEVQxqmgAFAEAuUMBCmEmg4kAAAAASUVORK5CYII=';

    /** The leaflet's cover answers 404 while this is true. */
    private bool $coverFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        // RemoteImageCache keeps files for a week: a URL a former run fetched would be answered from the disk.
        File::deleteDirectory(storage_path('app/cache/images'));
        $this->coverFails = false;
        Http::fake(fn ($request) => $this->coverFails && str_contains($request->url(), '/pages/002.webp')
            ? Http::response('', 404)
            : Http::response((string) base64_decode(self::PIXEL), 200, ['Content-Type' => 'image/png']));
    }

    public function test_every_slide_of_a_dish_in_every_shape_renders_with_the_label_of_the_picture(): void
    {
        $item = $this->recipeItem();

        foreach (['square', 'portrait', 'story'] as $shape) {
            foreach (['recipe', 'recipe-hook', 'recipe-cta'] as $name) {
                $html = $this->html($item->brand, $item, "kinds/{$name}-{$shape}");

                $this->assertStringContainsString('AI ilustracija', $html, "{$name}-{$shape}");
                $this->assertStringContainsString('data:image/png;base64,', $html, "{$name}-{$shape}");
            }
        }
    }

    public function test_the_card_prints_the_dish_and_the_three_numbers_and_no_product(): void
    {
        $item = $this->recipeItem();
        $html = $this->html($item->brand, $item, 'kinds/recipe-story');

        foreach (['Pileća prsa s mahunama u vrhnju', '4 / 7', '8,96 €', '2,20 €', 'Ribola', '25 min · 4 porcije · Vrijedi do 17. 11.', 'Recept i popis za kupnju'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        // The ingredients are not shown unless the brand asks for them.
        $this->assertStringNotContainsString('Zelena mahuna', $html);
        $this->assertStringNotContainsString('rc-chip"><span>', $html);
    }

    public function test_the_ingredients_stand_under_the_name_when_the_brand_asks_for_them(): void
    {
        $item = $this->recipeItem();
        $item->brand->voice = [...$item->brand->voice, 'recipe_highlights' => true];
        $html = $this->html($item->brand, $item, 'kinds/recipe-story');

        $this->assertStringContainsString('<span class="rc-chip"><span>✓</span>Zelena mahuna</span>', $html);
        $this->assertSame(4, mb_substr_count($html, '<span class="rc-chip">'));
    }

    public function test_a_saving_under_a_euro_leaves_no_tile_and_no_pill(): void
    {
        $item = $this->recipeItem(feedItem: [...$this->recipeFeedItem(), 'raw' => ['recipe' => [...$this->recipeFeedItem()['raw']['recipe'], 'saving_cents' => 50]]]);
        $item->brand->voice = [...$item->brand->voice, 'recipe_hook' => 'figure'];

        $card = $this->html($item->brand, $item, 'kinds/recipe-story');
        $hook = $this->html($item->brand, $item, 'kinds/recipe-hook-story');

        $this->assertStringNotContainsString('Ušteda', $card);
        $this->assertStringNotContainsString('ušteda', $hook);
        $this->assertStringContainsString('4 sastojka na akciji', $hook);
    }

    public function test_the_hook_is_the_question_the_number_or_the_time_as_the_brand_chooses(): void
    {
        $item = $this->recipeItem();
        $brand = $item->brand;

        $line = fn (string $choice): string => $this->html($brand->setAttribute('voice', [...$brand->voice, 'recipe_hook' => $choice]), $item, 'kinds/recipe-hook-story');

        $question = $line('question');
        $this->assertStringContainsString('Što kuhati ovaj tjedan?', $question);
        $this->assertStringNotContainsString('sastojka na akciji', $question);

        $figure = $line('figure');
        $this->assertStringContainsString('4 sastojka na akciji', $figure);
        $this->assertStringContainsString('ušteda 2,20 €', $figure);
        $this->assertStringNotContainsString('Što kuhati ovaj tjedan?', $figure);

        $time = $line('time');
        $this->assertStringContainsString('Gotovo za 25 minuta', $time);
        $this->assertStringContainsString('Sastojci iz letka', $time);
        // Never the sum first: it is the sum of one pack of each ingredient on sale.
        foreach ([$question, $figure, $time] as $hook) {
            $this->assertStringNotContainsString('8,96', $hook);
        }
    }

    public function test_the_end_card_says_where_the_link_is_as_the_channel_has_it(): void
    {
        $item = $this->recipeItem();

        $this->assertStringContainsString('Poveznica je u komentaru.', $this->html($item->brand, $item, 'kinds/recipe-cta-story'));
        $this->assertStringContainsString('Poveznica je u opisu.', $this->html($item->brand, $item, 'kinds/recipe-cta-story', ['link_in' => 'description']));
        $this->assertStringContainsString('Poveznica je u biografiji.', $this->html($item->brand, $item, 'kinds/recipe-cta-story', ['link_in' => 'bio']));
        $this->assertStringContainsString('Recept i popis su u aplikaciji Listo.', $this->html($item->brand, $item, 'kinds/recipe-cta-story'));
        $this->assertStringContainsString('Preuzmi Listo', $this->html($item->brand, $item, 'kinds/recipe-cta-story'));
    }

    public function test_the_leaflet_hook_opens_with_the_cover_of_the_shops_leaflet_and_the_dish(): void
    {
        $item = $this->recipeItem();
        $item->brand->voice = [...$item->brand->voice, 'recipe_hook' => 'leaflet'];

        foreach (['square', 'portrait', 'story'] as $shape) {
            $html = $this->html($item->brand, $item, "kinds/recipe-hook-{$shape}");

            $this->assertStringContainsString('class="lf-leaflet"', $html, $shape);
            $this->assertStringContainsString('class="lf-dish"', $html, $shape);
            $this->assertStringContainsString('Iz letka na tanjur', $html, $shape);
            $this->assertStringContainsString('<div class="rc-plaque">Ribola</div>', $html, $shape);
            $this->assertStringContainsString('AI ilustracija', $html, $shape);
            $this->assertStringNotContainsString('Što kuhati ovaj tjedan?', $html, $shape);
        }

        // The picture of the leaflet is fetched for the hook that shows it, and for no other.
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/pages/002.webp'));
    }

    public function test_the_other_hooks_do_not_fetch_the_leaflet_and_a_leaflet_that_cannot_be_fetched_falls_back_to_the_question(): void
    {
        $item = $this->recipeItem();
        $item->brand->voice = [...$item->brand->voice, 'recipe_hook' => 'question'];
        $question = $this->html($item->brand, $item, 'kinds/recipe-hook-story');

        $this->assertStringNotContainsString('lf-leaflet"', $question);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/pages/002.webp'));

        File::deleteDirectory(storage_path('app/cache/images'));
        $this->coverFails = true;
        $item->brand->voice = [...$item->brand->voice, 'recipe_hook' => 'leaflet'];
        $fallback = $this->html($item->brand, $item, 'kinds/recipe-hook-story');

        $this->assertStringNotContainsString('class="lf-leaflet"', $fallback);
        $this->assertStringContainsString('Što kuhati ovaj tjedan?', $fallback);
    }

    public function test_the_german_slides_are_german(): void
    {
        $item = $this->recipeItem(market: 'at');

        $card = $this->html($item->brand, $item, 'kinds/recipe-portrait');
        $cta = $this->html($item->brand, $item, 'kinds/recipe-cta-portrait');

        foreach (['Hendlfilet mit Spinat und Erdäpfeln', '4 / 6', '15,26 €', '2,70 €', 'Im Angebot', 'Zusammen', 'Ersparnis', 'Eurospar', '30 Min. · 4 Portionen · Gültig bis 18.11.', 'KI-Illustration'] as $text) {
            $this->assertStringContainsString($text, $card);
        }
        $this->assertStringContainsString('Rezept und Einkaufsliste findest du in der App Listo.', $cta);
        $this->assertStringContainsString('Hol dir Listo', $cta);
        $this->assertStringNotContainsString('AI ilustracija', $card);
    }

    public function test_a_dish_is_told_in_three_slides_in_the_same_order_as_the_other_kinds(): void
    {
        $registry = app(TemplateRegistry::class);

        $this->assertSame(['kinds/recipe-hook-story', 'kinds/recipe-story', 'kinds/recipe-cta-story'], $registry->slidesFor(\App\Enums\ContentKind::Recipe, 'story'));
        $this->assertSame(['kinds/recipe-hook-portrait', 'kinds/recipe-portrait', 'kinds/recipe-cta-portrait'], $registry->slidesFor(\App\Enums\ContentKind::Recipe, 'portrait'));
        // The card of a dish is what a single image shows.
        $this->assertSame('kinds/recipe-portrait', $registry->defaultFor(\App\Enums\ContentKind::Recipe, 'portrait'));
        $this->assertSame('kinds/recipe-story', $registry->storyFor(\App\Enums\ContentKind::Recipe));

        $this->assertSame('hook', $registry->roleOf('kinds/recipe-hook-story'));
        $this->assertSame('closing', $registry->roleOf('kinds/recipe-cta-story'));
        $this->assertTrue($registry->isClosing('kinds/recipe-cta-portrait'));
        $this->assertSame('card', $registry->roleOf('kinds/recipe-story'));
    }

    public function test_the_cover_of_a_roundup_of_dishes_says_how_many_and_shows_them(): void
    {
        $brand = $this->recipeBrand();
        $items = collect(range(0, 4))->map(fn (int $index): ContentItem => $this->recipeItem($brand, [...$this->recipeFeedItem($index), 'id' => "recipe:cover-{$index}"]));

        $view = app(TemplateData::class)->forDigest($items, $brand, 'Što kuhati ovaj tjedan: 5 jela iz akcija', $brand->name);
        $html = app(ImageRenderer::class)->html($brand, 'kinds/digest-cover-portrait', $view);

        $this->assertStringContainsString('Što kuhati ovaj tjedan: 5 jela iz akcija', $html);
        $this->assertStringContainsString('5 jela iz akcija', $html);
        $this->assertSame(4, mb_substr_count($html, 'class="rv__dish"'));
        $this->assertStringContainsString('AI ilustracija', $html);
        $this->assertStringContainsString('Prelistaj', $html);
        // Every other kind's cover is what it was.
        $this->assertStringNotContainsString('rv__dish', $this->html($brand, $this->recipeItem($brand, [...$this->recipeFeedItem(1), 'id' => 'recipe:x']), 'kinds/recipe-portrait'));
    }

    public function test_the_video_of_a_dish_is_its_three_slides_and_the_voice_has_one_line_for_each(): void
    {
        $item = $this->recipeItem();
        $draft = \App\Actions\CreateDraft::class;
        $post = app($draft)->execute($item, $this->recipeAccounts($item->brand, [\App\Enums\Platform::TikTok]), render: false);
        $scenes = app(ScenePlanner::class)->forDraft($post->load('contentItems', 'brand'));

        $this->assertSame(['hook', 'card', 'closing'], array_map(fn ($scene): string => $scene->role, $scenes));

        $script = app(\App\Voiceover\ScriptBuilder::class)->build($item->brand, $scenes, $post->contentItems, $post->title);

        $this->assertCount(3, $script->lines);
        $this->assertSame('4 od 7 sastojaka je na akciji u trgovini Ribola.', $script->lines[1]->text);
        $this->assertStringEndsWith('Poveznica je u komentaru.', $script->lines[2]->text);

        $onYoutube = app(\App\Voiceover\ScriptBuilder::class)->build($item->brand, $scenes, $post->contentItems, $post->title, 'description');
        $this->assertStringEndsWith('Poveznica je u opisu.', $onYoutube->lines[2]->text);
    }

    private function html(Brand $brand, ContentItem $item, string $key, array $overrides = []): string
    {
        $view = app(TemplateData::class)->forItem($item, $brand, $overrides);

        return app(ImageRenderer::class)->html($brand, $key, $view);
    }
}
