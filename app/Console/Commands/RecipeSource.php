<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Enums\SourceType;
use App\Models\Brand;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The source of a brand's dishes and its rules, made from the brand's own deals source: same address, same key, same country,
 * `query.kind=recipe`. Nothing is switched on: the source is off, and so is every rule, and every rule asks for approval. A
 * person turns them on in the panel after "Testiraj vezu" has shown dishes, and turns "Traži odobrenje" off when the first few
 * have been looked at (docs/listo-recipes.md).
 *
 * One rule per channel the brand has an account for: a video where the channel takes only video (TikTok, YouTube) and on Instagram
 * (a Reel, like the leaflet's), an image on a Facebook Page. A Facebook group is posted by hand and gets none. The weekly roundup of dishes
 * is a series of the brand (`brands.digests`: kind Recept), not a rule.
 */
final class RecipeSource extends Command
{
    /** The formats a dish is posted in, per channel. Facebook Pages like a picture with the link in the first comment; the rest take a video. */
    private const FORMATS = [
        'fb_page' => ContentFormat::Image,
        'ig_business' => ContentFormat::Video,
        'tiktok' => ContentFormat::Video,
        'youtube' => ContentFormat::Video,
    ];

    protected $signature = 'hub:recipe-source
        {brand : Slug of the brand (e.g. uselisto or uselisto-at)}
        {--from= : Name of the source to copy the address and key from (default: the brand\'s deals source)}
        {--name= : Name of the new source (default: listo-recepti, or listo-recepti-at for a brand whose source ends in -at)}
        {--dry-run : Show what would be made and make nothing}';

    protected $description = 'Make the source and the rules for a brand\'s dishes, all switched off and asking for approval';

    public function handle(): int
    {
        $brand = Brand::query()->where('slug', (string) $this->argument('brand'))->first();

        if ($brand === null) {
            $this->error("No brand '{$this->argument('brand')}'.");

            return self::FAILURE;
        }

        $base = filled($this->option('from'))
            ? $brand->sources()->where('name', (string) $this->option('from'))->first()
            : $brand->sources()->where('type', SourceType::SocialFeedV1->value)->orderBy('id')->get()
                ->first(fn (Source $source): bool => blank($this->query($source, 'kind')) || $this->query($source, 'kind') === 'deal');

        if ($base === null) {
            $this->error('The brand has no Social Feed v1 source to copy the address and key from; make one in the panel or pass --from.');

            return self::FAILURE;
        }

        $name = filled($this->option('name')) ? (string) $this->option('name') : (str_ends_with($base->name, '-at') ? 'listo-recepti-at' : 'listo-recepti');

        if ($brand->sources()->where('name', $name)->exists()) {
            $this->error("Source '{$name}' already exists; nothing was made.");

            return self::FAILURE;
        }

        if (blank($this->query($base, 'country'))) {
            $this->warn("⚠ Source '{$base->name}' does not name its country (query.country); the feed will answer for its default market, and the hub refuses a feed that is another brand's.");
        }

        $platforms = $brand->socialAccounts()->where('status', AccountStatus::Active->value)->get()
            ->map(fn ($account): Platform => $account->platform)
            ->unique()
            ->filter(fn (Platform $platform): bool => array_key_exists($platform->value, self::FORMATS))
            ->values();

        if ($platforms->isEmpty()) {
            $this->warn("⚠ '{$brand->slug}' has no active account on a channel a dish is posted on; the source is made and the rules wait for accounts.");
            $platforms = collect(array_map(fn (string $value): Platform => Platform::from($value), array_keys(self::FORMATS)));
        }

        $config = [
            // The address, key and market of the deals source stay; what only a deal knows (a discount floor, a chain) does not.
            ...array_filter((array) $base->config, fn (string|int $key): bool => ! in_array((string) $key, ['query.kind', 'query.min_discount', 'query.chain', 'query.min_products', 'query.max_validity_days'], true), ARRAY_FILTER_USE_KEY),
            'query.kind' => 'recipe',
        ];

        $this->info("Source '{$name}' for '{$brand->slug}' from '{$base->name}': ".http_build_query(array_filter($config, fn (string|int $key): bool => str_starts_with((string) $key, 'query.'), ARRAY_FILTER_USE_KEY)));
        $this->table(['channel', 'format', 'rule'], $platforms->map(fn (Platform $platform): array => [$platform->value, self::FORMATS[$platform->value]->value, 'off, asks for approval, 1 a day'])->all());

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run: nothing was made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($brand, $base, $name, $config, $platforms): void {
            $source = $brand->sources()->create([
                'name' => $name,
                'type' => $base->type,
                'base_url' => $base->base_url,
                'auth_type' => $base->auth_type,
                'secret' => $base->secret,
                'config' => $config,
                'enabled' => false,
                'auto_publish_backlog' => false,
            ]);

            foreach ($platforms as $platform) {
                $source->autoPublishRules()->create([
                    'platform' => $platform,
                    'enabled' => false,
                    'requires_approval' => true,
                    'delay_minutes' => 0,
                    'daily_cap' => 1,
                    'format' => self::FORMATS[$platform->value],
                    'settings' => null,
                ]);
            }
        });

        $this->info('Made, and nothing is on. In the panel: Izvori → '.$name.' → Testiraj vezu (must show dishes of kind Recept), switch the source and the rules on, and put "recept" first in the brand\'s order of kinds (Raspored objava).');

        return self::SUCCESS;
    }

    /**
     * A filter of a source: the panel keeps them flat (`query.country`), and a nested `query` array is read as well.
     */
    private function query(Source $source, string $key): mixed
    {
        $config = (array) $source->config;

        return $config["query.{$key}"] ?? (is_array($config['query'] ?? null) ? ($config['query'][$key] ?? null) : null);
    }
}
