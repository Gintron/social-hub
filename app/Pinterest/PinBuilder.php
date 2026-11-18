<?php

declare(strict_types=1);

namespace App\Pinterest;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Rendering\TemplateData;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Makes the week's pins of a brand (config/pinterest.php): for each, the best dish of its kind that is on offer now, drawn as a pin.
 *
 * One pin per spec and week, whatever the number of runs (the command may be repeated; a missed Thursday is made up on Friday). A dish is not
 * pinned twice by the same spec, and two specs of one week never use the same dish, so the week's pins differ from each other and from the
 * weeks before. A spec with no dish left (a shop whose dishes are all used, a shop with none this week) makes no pin and says so in the log.
 * The pin keeps what the feed needs in `media_assets.params.pinterest`; nothing else stores it.
 */
final class PinBuilder
{
    public function __construct(
        private readonly TemplateData $data,
        private readonly PinImageRenderer $renderer,
    ) {}

    public static function weekOf(Brand $brand, CarbonInterface $at): string
    {
        return $at->copy()->setTimezone($brand->timezone ?: (string) config('hub.brand_default_timezone', 'Europe/Zagreb'))->format('o-\WW');
    }

    /**
     * @return list<MediaAsset> The pins made by this call.
     */
    public function buildWeek(Brand $brand, ?CarbonInterface $now = null): array
    {
        $config = config("pinterest.brands.{$brand->slug}");

        if (! is_array($config)) {
            return [];
        }

        $now ??= now();
        $week = self::weekOf($brand, $now);
        $existing = $this->pinAssets($brand);
        $usedThisWeek = $existing->filter(fn (MediaAsset $pin): bool => data_get($pin->params, 'pinterest.week') === $week)
            ->map(fn (MediaAsset $pin): mixed => data_get($pin->params, 'pinterest.item_id'))->filter()->all();
        $made = [];

        foreach ((array) $config['pins'] as $spec) {
            $done = $existing->contains(fn (MediaAsset $pin): bool => data_get($pin->params, 'pinterest.pin') === $spec['id']
                && data_get($pin->params, 'pinterest.week') === $week);

            if ($done) {
                continue;
            }

            $usedBySpec = $existing->filter(fn (MediaAsset $pin): bool => data_get($pin->params, 'pinterest.pin') === $spec['id'])
                ->map(fn (MediaAsset $pin): mixed => data_get($pin->params, 'pinterest.item_id'))->filter()->all();
            $item = $this->pick($brand, $spec, [...$usedBySpec, ...$usedThisWeek], $now);

            if ($item === null) {
                Log::info('Pinterest: no dish for the pin', ['brand' => $brand->slug, 'pin' => $spec['id'], 'week' => $week]);

                continue;
            }

            $made[] = $this->make($brand, $config, $spec, $item, $week);
            $usedThisWeek[] = $item->id;
        }

        return $made;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<int|string>  $excluded  Item ids not to use.
     */
    public function pick(Brand $brand, array $spec, array $excluded, CarbonInterface $now): ?ContentItem
    {
        return ContentItem::query()
            ->where('brand_id', $brand->id)
            ->where('kind', ContentKind::Recipe->value)
            ->where('expires_at', '>', $now)
            ->get()
            ->filter(function (ContentItem $item) use ($spec, $excluded): bool {
                $recipe = (array) data_get($item->raw, 'recipe', []);

                return ! in_array($item->id, $excluded, true)
                    && filled($item->imageUrl('primary'))
                    && (($spec['chain'] ?? null) === null || ($recipe['chain'] ?? null) === $spec['chain'])
                    && (($spec['max_minutes'] ?? null) === null || (int) ($recipe['minutes'] ?? PHP_INT_MAX) <= (int) $spec['max_minutes']);
            })
            ->sortBy([
                fn (ContentItem $a, ContentItem $b): int => (int) data_get($b->raw, 'recipe.saving_cents', 0) <=> (int) data_get($a->raw, 'recipe.saving_cents', 0),
                fn (ContentItem $a, ContentItem $b): int => $a->id <=> $b->id,
            ])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $config  The brand's block of config/pinterest.php.
     * @param  array<string, mixed>  $spec
     */
    private function make(Brand $brand, array $config, array $spec, ContentItem $item, string $week): MediaAsset
    {
        $model = $this->data->forItem($item, $brand, ['pin' => ['eyebrow' => $spec['eyebrow'], 'headline' => $spec['headline']]]);
        $asset = $this->renderer->render($brand, $model);

        $lead = $spec['lead'] ?? "Jede Woche neue Rezepte aus den Angeboten von {$spec['shop']}";
        $description = mb_trim("{$lead} – mit Preis der Zutaten im Angebot und Ersparnis. Mit einem Tipp kommen die Zutaten auf die Einkaufsliste in der Listo-App. {$config['ai_note']}");

        $asset->params = [
            ...($asset->params ?? []),
            'pinterest' => [
                'pin' => $spec['id'],
                'board' => $spec['board'],
                'week' => $week,
                'item_id' => $item->id,
                'title' => $spec['title'],
                'description' => $description,
                'link' => $this->link($brand, $spec),
            ],
        ];
        $asset->save();

        return $asset;
    }

    /**
     * Under the brand's site (Pinterest accepts only links on the claimed domain), tagged so the install can be traced (storeLinks.ts reads utm_*).
     *
     * @param  array<string, mixed>  $spec
     */
    private function link(Brand $brand, array $spec): string
    {
        return mb_rtrim((string) $brand->site_url, '/').$spec['path'].'?'.http_build_query([
            'utm_source' => 'pinterest',
            'utm_medium' => 'social',
            'utm_campaign' => 'pin-'.$spec['board'],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return Collection<int, MediaAsset>
     */
    private function pinAssets(Brand $brand): Collection
    {
        return MediaAsset::query()
            ->where('brand_id', $brand->id)
            ->where('template_key', ChromiumPinRenderer::TEMPLATE)
            ->get()
            ->filter(fn (MediaAsset $asset): bool => is_array(data_get($asset->params, 'pinterest')));
    }
}
