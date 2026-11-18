<?php

declare(strict_types=1);

namespace App\Pinterest;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Support\Wording;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The RSS 2.0 feed Pinterest reads for one board (Business account → Create → Auto-publish). Pinterest takes the picture from `enclosure`, the title and
 * description from the item, and requires the item's link to be on the claimed domain; it creates each new item once, oldest first, within 24 hours.
 */
final class PinFeed
{
    /**
     * @return Collection<int, MediaAsset> Newest first.
     */
    public function pins(Brand $brand, string $board): Collection
    {
        return MediaAsset::query()
            ->where('brand_id', $brand->id)
            ->where('template_key', ChromiumPinRenderer::TEMPLATE)
            ->where('created_at', '>=', now()->subDays((int) config('pinterest.feed_days', 60)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (MediaAsset $asset): bool => data_get($asset->params, 'pinterest.board') === $board);
    }

    public function xml(Brand $brand, string $board): string
    {
        $name = (string) config("pinterest.brands.{$brand->slug}.boards.{$board}");
        $site = mb_rtrim((string) $brand->site_url, '/');
        $items = $this->pins($brand, $board)->map(function (MediaAsset $asset): string {
            $pin = (array) data_get($asset->params, 'pinterest');
            $url = Storage::disk($asset->disk)->url($asset->path);

            return '<item>'
                .'<title>'.$this->e($pin['title']).'</title>'
                .'<link>'.$this->e($pin['link']).'</link>'
                .'<guid isPermaLink="false">listo-pin-'.$asset->id.'</guid>'
                .'<pubDate>'.$asset->created_at->toRfc2822String().'</pubDate>'
                .'<description>'.$this->e($pin['description']).'</description>'
                .'<enclosure url="'.$this->e($url).'" length="'.(int) $asset->bytes.'" type="image/jpeg"/>'
                .'</item>';
        })->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel>'
            .'<title>'.$this->e("{$brand->name} – {$name}").'</title>'
            .'<link>'.$this->e($site.'/rezepte').'</link>'
            .'<description>'.$this->e(Wording::get($brand, 'pin.feed_description', ['board' => $name])).'</description>'
            .'<language>'.$this->e(Wording::get($brand, 'pin.feed_language')).'</language>'
            .'<lastBuildDate>'.now()->toRfc2822String().'</lastBuildDate>'
            .$items
            .'</channel></rss>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
