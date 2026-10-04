<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Catalog\CatalogCopy;
use App\Catalog\CatalogDemo;
use App\Catalog\CatalogSceneBundle;
use App\Catalog\CatalogVideoPlan;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Rendering\CatalogVideo\CatalogFrameRenderer;
use App\Sources\ContentItemData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

/**
 * Look at the new-catalog video without publishing anything: a few frames as pictures. The scene draws itself for any
 * second, so "is the toast right at 4.4 s" is one command, not a render and a scrub.
 */
final class CatalogVideoPreview extends Command
{
    protected $signature = 'hub:catalog-stills
        {--fixture= : A Social Feed v1 file with a kind=catalog item (tests/Fixtures/catalog-feed-*.json)}
        {--item= : Id of a kind=catalog item in the hub}
        {--brand=uselisto : Brand slug (an unsaved Listo stands in when there is none)}
        {--at=1.0,2.4 : Seconds to draw, comma separated}
        {--where=comment : Where the link is: comment or bio}
        {--out= : Folder for the pictures (default storage/app/catalog-stills)}';

    protected $description = 'Draw single frames of the new-catalog video as PNG, for checking how it looks';

    public function handle(CatalogSceneBundle $bundle, CatalogFrameRenderer $frames): int
    {
        try {
            $item = $this->item();
            $demo = CatalogDemo::fromItem($item);
            $brand = Brand::query()->where('slug', (string) $this->option('brand'))->first()
                ?? new Brand(['slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com', 'voice' => ['cta' => 'Preuzmi Listo']]);

            $where = (string) $this->option('where') === CatalogCopy::WHERE_BIO ? CatalogCopy::WHERE_BIO : CatalogCopy::WHERE_COMMENT;
            $plan = CatalogVideoPlan::build($demo, CatalogCopy::voiceLines($demo, $where, (string) data_get($brand->voice, 'cta', 'Preuzmi Listo')));

            $work = storage_path('app/catalog-stills/work');
            File::deleteDirectory($work);
            $bundle->make($brand, $item, $demo, $plan, $where, $work);

            $out = (string) ($this->option('out') ?: storage_path('app/catalog-stills'));
            File::ensureDirectoryExists($out);
            $times = array_map('floatval', array_filter(explode(',', (string) $this->option('at')), fn (string $t): bool => $t !== ''));
            $paths = $frames->stills($work, $out, $times);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Trajanje po planu: %.2f s; dodiri: %s', $plan['duration'], implode(', ', array_map(fn (array $tap): string => sprintf('%.2f', $tap['at']), $plan['taps']))));
        $this->line(sprintf('Popis od %.2f, završna kartica od %.2f', $plan['list']['at'], $plan['cta']['at']));

        foreach ($paths as $path) {
            $this->info($path);
        }

        return self::SUCCESS;
    }

    private function item(): ContentItem
    {
        if (filled($this->option('item'))) {
            return ContentItem::query()->findOrFail((int) $this->option('item'));
        }

        $file = (string) $this->option('fixture');
        $payload = json_decode((string) file_get_contents($file), true);

        if (! is_array($payload) || ! isset($payload['items'][0])) {
            throw new InvalidArgumentException("U datoteci {$file} nema stavke.");
        }

        $data = ContentItemData::fromFeedItem($payload['items'][0]);

        return new ContentItem($data->toAttributes());
    }
}
