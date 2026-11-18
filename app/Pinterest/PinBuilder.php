<?php

declare(strict_types=1);

namespace App\Pinterest;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Rendering\TemplateData;
use App\Support\Wording;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Makes the week's pins of a brand (config/pinterest.php): for each, the best dish of its kind that is on offer now, drawn as a pin.
 *
 * One pin per spec and week, whatever the number of runs (the command may be repeated; a missed Thursday is made up on Friday). A dish is not
 * pinned twice by the same spec, and two specs of one week never use the same dish, so the week's pins differ from each other and from the
 * weeks before. A spec with no dish left (a shop whose dishes are all used, a shop with none this week) makes no pin and says so in the log.
 * A dish whose picture cannot be drawn does not stop the week: the next best dish takes its place, and a spec that fails on every try is reported (`$failed`, the log) while the others go on.
 * The pin keeps what the feed needs in `media_assets.params.pinterest`; nothing else stores it.
 */
final class PinBuilder
{
    /** Pinterest cuts a title at 100 characters. */
    private const TITLE_MAX = 100;

    /** Dishes tried for one pin before the pin is given up for the week. */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly TemplateData $data,
        private readonly PinImageRenderer $renderer,
    ) {}

    public static function weekOf(Brand $brand, CarbonInterface $at): string
    {
        return $at->copy()->setTimezone($brand->timezone ?: (string) config('hub.brand_default_timezone', 'Europe/Zagreb'))->format('o-\WW');
    }

    /**
     * @param  list<string>  $failed  Filled with one line per pin that could not be made, so the caller can tell a quiet week from a broken one.
     * @return list<MediaAsset> The pins made by this call.
     */
    public function buildWeek(Brand $brand, ?CarbonInterface $now = null, array &$failed = []): array
    {
        $config = config("pinterest.brands.{$brand->slug}");

        if (! is_array($config)) {
            return [];
        }

        // Pinterest takes only links on the claimed domain; a relative link would be skipped after the picture was drawn for nothing.
        if (! filter_var((string) $brand->site_url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Brand '{$brand->slug}' has no absolute site_url, so its pins would have no valid link.");
        }

        $now ??= now();
        $week = self::weekOf($brand, $now);
        $records = $this->pinAssets($brand)->map(fn (MediaAsset $pin): array => (array) data_get($pin->params, 'pinterest'));
        $usedThisWeek = $records->whereStrict('week', $week)->pluck('item_id')->filter()->all();
        $candidates = $this->candidates($brand);
        $made = [];

        foreach ((array) $config['pins'] as $spec) {
            $own = $records->whereStrict('pin', $spec['id']);

            if ($own->contains('week', $week)) {
                continue;
            }

            $excluded = [...$own->pluck('item_id')->filter()->all(), ...$usedThisWeek];
            $asset = null;
            $error = null;

            for ($attempt = 1; $attempt <= self::ATTEMPTS && $asset === null; $attempt++) {
                $item = $this->pick($candidates, $spec, $excluded);

                if ($item === null) {
                    break;
                }

                try {
                    $asset = $this->make($brand, $spec, $item, $week);
                    $usedThisWeek[] = $item->id;
                } catch (Throwable $e) {
                    // A picture that cannot be fetched or drawn is that dish's problem: the next best dish takes the pin, the other pins go on.
                    $excluded[] = $item->id;
                    $error = $e->getMessage();
                    Log::error('hub.pinterest.pin_failed', ['brand' => $brand->slug, 'pin' => $spec['id'], 'item' => $item->id, 'error' => $e->getMessage()]);
                }
            }

            if ($asset !== null) {
                $made[] = $asset;
            } elseif ($error !== null) {
                $failed[] = "{$spec['id']}: {$error}";
            } else {
                Log::info('Pinterest: no dish for the pin', ['brand' => $brand->slug, 'pin' => $spec['id'], 'week' => $week]);
            }
        }

        return $made;
    }

    /**
     * How many pins the brand has for the week, made now or by an earlier run: none at all means the feeds will stay as they were.
     */
    public function pinsOfWeek(Brand $brand, CarbonInterface $now): int
    {
        $week = self::weekOf($brand, $now);

        return $this->pinAssets($brand)->filter(fn (MediaAsset $pin): bool => data_get($pin->params, 'pinterest.week') === $week)->count();
    }

    /**
     * The dishes a pin may show, dearest saving first: live (offers not ended, page not found gone) and with a picture. Loaded once for all pins of the run.
     *
     * @return Collection<int, ContentItem>
     */
    private function candidates(Brand $brand): Collection
    {
        return ContentItem::query()
            ->where('brand_id', $brand->id)
            ->where('kind', ContentKind::Recipe->value)
            ->live()
            ->get()
            ->filter(fn (ContentItem $item): bool => filled($item->imageUrl('primary')))
            ->sortBy([
                fn (ContentItem $a, ContentItem $b): int => (int) data_get($b->raw, 'recipe.saving_cents', 0) <=> (int) data_get($a->raw, 'recipe.saving_cents', 0),
                fn (ContentItem $a, ContentItem $b): int => $a->id <=> $b->id,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, ContentItem>  $candidates
     * @param  array<string, mixed>  $spec
     * @param  list<int|string>  $excluded  Item ids not to use.
     */
    private function pick(Collection $candidates, array $spec, array $excluded): ?ContentItem
    {
        return $candidates->first(function (ContentItem $item) use ($spec, $excluded): bool {
            $recipe = (array) data_get($item->raw, 'recipe', []);

            return ! in_array($item->id, $excluded, true)
                && (($spec['chain'] ?? null) === null || ($recipe['chain'] ?? null) === $spec['chain'])
                && (($spec['max_minutes'] ?? null) === null || (int) ($recipe['minutes'] ?? PHP_INT_MAX) <= (int) $spec['max_minutes']);
        });
    }

    /**
     * The picture is the dish's, and so are the first words of the title and description: Pinterest search matches on them, and the dish's name does not end
     * with its offers. The link stays on the page that stays up.
     *
     * @param  array<string, mixed>  $spec
     */
    private function make(Brand $brand, array $spec, ContentItem $item, string $week): MediaAsset
    {
        $model = $this->data->forItem($item, $brand, ['pin' => ['eyebrow' => $spec['eyebrow'], 'headline' => $spec['headline']]]);
        $asset = $this->renderer->render($brand, $model);

        $lead = $spec['lead'] ?? Wording::get($brand, 'pin.lead', ['shop' => $spec['shop']]);
        $description = mb_trim(Wording::get($brand, 'pin.description', ['dish' => $item->title, 'lead' => $lead, 'ai' => Wording::get($brand, 'recipe.ai_note')]));

        $asset->params = [
            ...($asset->params ?? []),
            'pinterest' => [
                'pin' => $spec['id'],
                'board' => $spec['board'],
                'week' => $week,
                'item_id' => $item->id,
                'title' => mb_strimwidth("{$item->title} – {$spec['title']}", 0, self::TITLE_MAX, '…'),
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
