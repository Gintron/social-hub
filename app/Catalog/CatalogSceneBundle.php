<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Rendering\RemoteImageCache;
use App\Rendering\TemplateData;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Everything Chromium needs to draw the video's frames, in one folder: the scene (resources/catalog-video), its
 * fonts, the leaflet's pages and the three crops as files, and `data.js` — what is said, shown and when.
 *
 * Files rather than data: URIs because a page of the leaflet is 300 kB and the scene wants several; a folder is
 * also what one looks at when a frame is wrong.
 */
final class CatalogSceneBundle
{
    public function __construct(private readonly RemoteImageCache $images) {}

    /**
     * @param  array<string, mixed>  $timeline  CatalogVideoPlan::build()
     * @return array<string, mixed> What was written to data.js.
     *
     * @throws RuntimeException When a page of the leaflet cannot be fetched: there is nothing to show without it.
     */
    public function make(Brand $brand, ContentItem $item, CatalogDemo $demo, array $timeline, string $where, string $dir): array
    {
        File::ensureDirectoryExists($dir.'/assets');

        $source = resource_path('catalog-video');
        File::copy($source.'/scene.html', $dir.'/index.html');
        File::copy($source.'/scene.css', $dir.'/scene.css');
        File::copy($source.'/scene.js', $dir.'/scene.js');
        File::copyDirectory($source.'/fonts', $dir.'/fonts');

        $pages = [];

        foreach ($demo->pages as $page) {
            $file = $this->fetch($page['url'], $dir, 'page-'.$page['number']);

            if ($file === null) {
                throw new RuntimeException("Stranica {$page['number']} letka se ne može dohvatiti: {$page['url']}");
            }

            $pages[] = ['number' => $page['number'], 'src' => $file, 'w' => $page['width'], 'h' => $page['height']];
        }

        $taps = [];

        foreach ($demo->taps as $index => $tap) {
            // A crop that is gone leaves its row of the list without a picture; the list is still true.
            $crop = $this->fetch($tap['crop'], $dir, 'crop-'.($index + 1));

            $taps[] = [
                'page' => $tap['page'],
                'bbox' => $tap['bbox'],
                'point' => $tap['point'],
                'brand' => $tap['brand'],
                'name' => $tap['name'],
                'priceCents' => $tap['price_cents'],
                'unit' => $tap['unit'],
                'crop' => $crop ?? '',
            ];
        }

        $logo = $this->fetch($item->imageUrl('logo'), $dir, 'chain-logo');
        $icon = $this->icon($dir);

        $cta = mb_trim((string) data_get($brand->voice, 'cta', ''));
        $theme = (array) config('catalog_video.theme');

        $data = [
            'fps' => CatalogVideoPlan::FPS,
            'theme' => $theme,
            'brand' => [
                'name' => $brand->name,
                'site' => TemplateData::displayUrl($brand->site_url) ?? '',
                'icon' => $icon,
            ],
            'chain' => ['name' => $demo->chainName, 'slug' => $demo->chain, 'logo' => $logo],
            'title' => CatalogCopy::title($demo),
            'validity' => CatalogCopy::validity($demo),
            'titles' => ['tap' => ['Dodirni proizvod.', 'Dodan je na listu.'], 'list' => ['Tvoja lista', 'je spremna.']],
            'pageCount' => $demo->pageCount,
            'pages' => $pages,
            'taps' => $taps,
            'totalCents' => $demo->totalCents,
            'currency' => $demo->currency,
            'locale' => $demo->locale,
            'cta' => ['headline' => $cta === '' ? 'Preuzmi '.$brand->name : $cta, 'link' => CatalogCopy::linkSentence($where)],
            'timeline' => $timeline,
        ];

        File::put($dir.'/data.js', 'window.SCENE = '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).';'."\n");

        return $data;
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

        $mime = mime_content_type($path);
        $extension = match ($mime) {
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/svg+xml' => 'svg',
            default => null,
        };

        if ($extension === null) {
            return null;
        }

        File::copy($path, "{$dir}/assets/{$name}.{$extension}");

        return "assets/{$name}.{$extension}";
    }

    /**
     * The app's icon on the lockup: the video is a copy of the app, and so is its mark.
     */
    private function icon(string $dir): string
    {
        File::copy(resource_path('catalog-video/listo-icon.png'), $dir.'/assets/brand.png');

        return 'assets/brand.png';
    }
}
