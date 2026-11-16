<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Catalog\CatalogVideoPlan;
use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Rendering\RemoteImageCache;
use App\Support\Wording;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Everything Chromium needs to draw the frames of a dish's video, in one folder: the scene (resources/recipe-video), its fonts, the
 * pictures of the dishes as files, and `data.js` — what is said, shown and when. The same shape as the new-catalog video's bundle
 * (CatalogSceneBundle), so the same frame renderer draws it.
 *
 * What it prints is what the slides of a dish print (RecipeCopy): the dish, how many of its ingredients are on sale, the sum, the
 * saving and its main ingredients by name. Never a product, a brand, a price or a picture from a leaflet (RecipeInfo). The hook shows
 * up to two more dishes of the brand that are live now, each with its own count: a reason to look, not a claim about this post.
 */
final class RecipeSceneBundle
{
    /** The hook shows the dish of the post and at most this many others before it. */
    public const OTHER_DISHES = 2;

    public function __construct(private readonly RemoteImageCache $images) {}

    /**
     * The other dishes the hook may show: live, postable as dishes, not restricted, with a picture. Chosen by the dish of the post, not by
     * the clock, so every render of a draft shows the same ones while they are live.
     *
     * @return list<ContentItem>
     */
    public function companions(ContentItem $item): array
    {
        return ContentItem::query()
            ->where('brand_id', $item->brand_id)
            ->where('kind', ContentKind::Recipe->value)
            ->whereKeyNot($item->id)
            ->live()
            ->latest('id')
            ->limit(40)
            ->get()
            // Nothing the source declared regulated (alcohol) is shown beside a dish it has nothing to do with.
            ->filter(fn (ContentItem $other): bool => ($other->restricted ?? []) === [] && RecipeInfo::tryFromItem($other) !== null && $other->imageUrl() !== null)
            ->sortBy(fn (ContentItem $other): int => crc32($item->external_id.'|'.$other->external_id))
            ->values()
            ->all();
    }

    /**
     * How many dishes the hook will show and how many rows the list has: what the plan of the video needs before the bundle is made.
     *
     * @return array{dishes: int, rows: int}
     */
    public function shape(ContentItem $item, Brand $brand): array
    {
        return [
            'dishes' => 1 + min(self::OTHER_DISHES, count($this->companions($item))),
            'rows' => count($this->highlights($item, $brand)),
        ];
    }

    /**
     * @param  array<string, mixed>  $timeline  RecipeVideoPlan::build()
     * @return array<string, mixed> What was written to data.js.
     *
     * @throws RuntimeException When the picture of the dish cannot be fetched: there is nothing to show without it.
     */
    public function make(Brand $brand, ContentItem $item, array $timeline, string $where, string $dir): array
    {
        File::ensureDirectoryExists($dir.'/assets');

        $source = resource_path('recipe-video');
        File::copy($source.'/scene.html', $dir.'/index.html');
        File::copy($source.'/scene.css', $dir.'/scene.css');
        File::copy($source.'/scene.js', $dir.'/scene.js');
        // The app's fonts are the new-catalog video's: one copy of them in the repository.
        File::copyDirectory(resource_path('catalog-video/fonts'), $dir.'/fonts');
        File::copy(resource_path('catalog-video/listo-icon.png'), $dir.'/assets/brand.png');

        $info = RecipeInfo::fromItem($item);
        $language = $brand->languageCode();
        $words = fn (string $key, array $replace = []): string => Wording::get($language, $key, $replace);
        $view = RecipeCopy::view($item, $brand, $where) ?? throw new RuntimeException('Stavka nema blok raw.recipe.');

        $picture = $this->fetch($item->imageUrl(), $dir, 'dish-0');

        if ($picture === null) {
            throw new RuntimeException('Slika jela se ne može dohvatiti: '.($item->imageUrl() ?? '(nema je)'));
        }

        // The hook: other dishes first, the dish of the post last, so the screen that follows is about the plate just seen.
        $dishes = [];
        $next = null;
        $wanted = max(0, count($timeline['swaps']) - 1);

        foreach ($this->companions($item) as $index => $other) {
            if (count($dishes) >= $wanted && $next !== null) {
                break;
            }

            $file = $this->fetch($other->imageUrl(), $dir, 'dish-'.($index + 1));

            if ($file === null) {
                continue;
            }

            if (count($dishes) < $wanted) {
                $dishes[] = ['image' => $file, 'title' => (string) $other->title, ...$this->sticker(RecipeInfo::fromItem($other), $language)];
            } else {
                // One more plate under the card of the app's screen, as the app's own list goes on.
                $next = ['image' => $file];
            }
        }

        // A companion whose picture is gone leaves the hook with fewer dishes than planned: the times of the plan are the first ones.
        $timeline['swaps'] = array_slice($timeline['swaps'], 0, count($dishes) + 1);

        $style = (string) $view['hook_style'];
        $dishes[] = ['image' => $picture, 'title' => (string) $item->title, ...$this->sticker($info, $language, $style)];

        $lines = RecipeCopy::voiceLines($item, $brand, $where);
        $saving = $info->shownSavingCents();
        $summary = mb_trim((string) $item->body_text);

        $data = [
            'fps' => CatalogVideoPlan::FPS,
            'language' => $language,
            'brand' => ['app' => RecipeCopy::appName($brand), 'icon' => 'assets/brand.png'],
            'aiLabel' => $info->aiImage ? $view['ai_label'] : null,
            'hook' => ['headline' => mb_rtrim($lines['hook'], '.'), 'style' => $style, 'dishes' => $dishes],
            'hero' => [
                'image' => $picture,
                'title' => (string) $item->title,
                'summary' => $summary !== '' && mb_strlen($summary) <= 110 ? $summary : null,
                'meta' => $words('recipe.card.minutes', ['minutes' => $info->minutes]).' · '
                    .$words('recipe.card.servings', ['count' => $info->servings, 'portions' => Wording::plural($language, $info->servings, 'portions')]),
                'chainLine' => implode(' · ', array_filter([$view['chain'], $view['hook_on_sale']])),
                'sum' => RecipeInfo::money($info->totalCents),
                // The old prices of the same packs, only beside a saving worth saying (RecipeInfo::MIN_SAVING_CENTS).
                'old' => $saving === null ? null : RecipeInfo::money($info->totalCents + $saving),
                'saving' => $saving === null ? null : $words('app.cook.saving', ['amount' => RecipeInfo::money($saving)]),
                'sumLabel' => $words('recipe.scene.sum_note'),
            ],
            'next' => $next,
            // The app's own labels in the copy of its screens, as the app shows them in this language (app/locales).
            'ui' => [
                'title' => $words('app.cook.title'), 'segA' => $words('app.cook.from_offers'), 'segB' => $words('app.cook.from_list'),
                'filters' => $words('app.cook.filters'),
                'tabs' => [$words('app.catalogs'), $words('app.search'), $words('app.cook'), $words('app.lists'), $words('app.settings')],
                'lists' => $words('app.lists'), 'mine' => $words('app.mine'), 'shared' => $words('app.shared'), 'toBuy' => $words('app.to_buy'),
                'onSale' => $view['list_on_sale'], 'added' => $view['list_added'],
            ],
            'list' => ['rows' => $this->highlights($item, $brand)],
            'cta' => [
                'line' => $view['cta_line'],
                'button' => $view['cta_get'],
                'link' => $view['link_sentence'],
                'aiNote' => $info->aiImage ? $view['ai_note'] : null,
            ],
            'timeline' => $timeline,
        ];

        File::put($dir.'/data.js', 'window.SCENE = '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).';'."\n");

        return $data;
    }

    /**
     * The yellow sticker on a dish of the hook. It never says the sum (one dear ingredient blows it up, docs/listo-recipes.md): it says
     * how many ingredients are on sale, or, on the dish of the post when the hook itself already says that number, the saving against the
     * leaflet (when it is worth saying) or how long the dish takes.
     *
     * @return array{big: string, small: string}
     */
    private function sticker(RecipeInfo $info, string $language, ?string $style = null): array
    {
        if ($style === RecipeCopy::HOOK_FIGURE) {
            $saving = $info->shownSavingCents();

            return $saving !== null
                ? ['big' => RecipeInfo::money($saving), 'small' => Wording::get($language, 'recipe.card.saving_label_long')]
                : [
                    'big' => Wording::get($language, 'recipe.card.minutes', ['minutes' => $info->minutes]),
                    'small' => Wording::get($language, 'recipe.card.servings', ['count' => $info->servings, 'portions' => Wording::plural($language, $info->servings, 'portions')]),
                ];
        }

        return [
            'big' => $info->ingredientsOnSale.' '.Wording::plural($language, $info->ingredientsOnSale, 'ingredients'),
            'small' => Wording::get($language, 'recipe.list.on_sale'),
        ];
    }

    /**
     * The rows of the shopping list: the dish's main ingredients, when the source sends them and the brand wants them shown
     * (`voice.recipe_highlights`, as on the last slide).
     *
     * @return list<string>
     */
    private function highlights(ContentItem $item, Brand $brand): array
    {
        return (bool) data_get($brand->voice, 'recipe_highlights', false) ? (RecipeInfo::tryFromItem($item)?->highlights ?? []) : [];
    }

    /**
     * A remote image as a file in the bundle, under a name Chromium can type by: the extension is what its mime says.
     */
    private function fetch(?string $url, string $dir, string $name): ?string
    {
        $path = $this->images->path($url);

        if ($path === null) {
            return null;
        }

        $extension = match (mime_content_type($path)) {
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => null,
        };

        if ($extension === null) {
            return null;
        }

        File::copy($path, "{$dir}/assets/{$name}.{$extension}");

        return "assets/{$name}.{$extension}";
    }
}
