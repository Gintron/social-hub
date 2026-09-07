<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Rendering\ImageRenderer;
use App\Rendering\TemplateData;
use App\Rendering\TemplateRegistry;
use Illuminate\Console\Command;

final class RenderPreview extends Command
{
    protected $signature = 'hub:render-preview
        {template : Template key, e.g. kinds/job-square}
        {--item= : Content item id to render (default: newest item matching the template kind)}
        {--brand= : Brand slug (default: the item\'s brand)}
        {--html : Print the HTML instead of rendering an image}';

    protected $description = 'Render one image template for a content item and print where it landed';

    public function handle(ImageRenderer $renderer, TemplateData $data, TemplateRegistry $templates): int
    {
        $key = (string) $this->argument('template');
        $template = $templates->get($key);

        $item = filled($this->option('item'))
            ? ContentItem::query()->find((int) $this->option('item'))
            : ContentItem::query()->whereIn('kind', $template['kinds'])->latest('id')->first();

        if ($item === null) {
            $this->error('No content item to render. Sync a source first or pass --item.');

            return self::FAILURE;
        }

        $brand = filled($this->option('brand'))
            ? Brand::query()->where('slug', (string) $this->option('brand'))->firstOrFail()
            : $item->brand;

        $viewModel = $data->forItem($item, $brand);

        if ($this->option('html')) {
            $this->output->write($renderer->html($brand, $key, $viewModel));

            return self::SUCCESS;
        }

        $started = microtime(true);
        $asset = $renderer->render($brand, $key, $viewModel);

        $this->info(sprintf('Rendered %s in %.1fs → %s (%d×%d, %d KB)', $key, microtime(true) - $started, $asset->publicUrl(), $asset->width, $asset->height, (int) (($asset->bytes ?? 0) / 1024)));
        $this->line('Local file: '.$asset->absolutePath());

        return self::SUCCESS;
    }
}
