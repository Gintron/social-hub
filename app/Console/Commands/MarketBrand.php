<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SourceType;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\Source;
use App\Support\Wording;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A brand for another market of a site that already has one: Listo Österreich next to Listo.
 *
 * The new brand is its own unit: its own channels, its own sources (the same feeds asked for the other country), its
 * own rules and its own words. That is what keeps the markets apart — the hub publishes a brand's items on that brand's
 * accounts only (ApplyAutoPublishRules, AutoLineup, ScheduleDigest pick accounts by brand and platform), so Austrian
 * offers can reach Austrian channels and nothing else.
 *
 * Nothing goes out by itself: the sources start switched off and every rule starts off and asking for approval. The
 * texts the hub publishes word for word (call to action, pitch, first step) are never copied from the other language;
 * the ones not given here stay empty. The voice-over keeps its model but no voice, because a voice is chosen by ear.
 */
final class MarketBrand extends Command
{
    protected $signature = 'hub:market-brand
        {from : Slug of the brand to start from (e.g. uselisto)}
        {slug : Slug of the new brand; the feed must report the same (e.g. uselisto-at)}
        {--name= : Name of the new brand (e.g. "Listo Österreich")}
        {--country= : Country the feeds are asked for, as query.country (e.g. at)}
        {--language=de : Language of the new brand\'s channels: hr or de}
        {--timezone=Europe/Vienna : Time zone of its posting windows}
        {--site-url= : Its site (default: the other brand\'s)}
        {--cta= : Call to action, said and shown word for word (e.g. "Hol dir Listo")}
        {--pitch= : One sentence about the brand, shown where a link cannot be followed}
        {--activation= : The first step it asks of a new user}
        {--hashtags= : Comma-separated hashtags without #}
        {--dry-run : Show what would be made and make nothing}';

    protected $description = 'Make a brand for another market of a site: own sources, rules and words, nothing switched on';

    public function handle(): int
    {
        $from = Brand::query()->where('slug', (string) $this->argument('from'))->first();
        $slug = mb_trim((string) $this->argument('slug'));
        $country = mb_strtolower(mb_trim((string) $this->option('country')));
        $language = mb_strtolower(mb_trim((string) $this->option('language')));
        $name = mb_trim((string) $this->option('name'));

        if ($from === null) {
            $this->error("No brand '{$this->argument('from')}'.");

            return self::FAILURE;
        }

        if ($slug === '' || Brand::query()->where('slug', $slug)->exists()) {
            $this->error("Brand '{$slug}' already exists or is empty; nothing was made.");

            return self::FAILURE;
        }

        if (preg_match('/^[a-z]{2}$/', $country) !== 1) {
            $this->error('--country takes a two-letter country code (e.g. at).');

            return self::FAILURE;
        }

        if (! in_array($language, Wording::LANGUAGES, true)) {
            $this->error('--language takes one of: '.implode(', ', Wording::LANGUAGES).'.');

            return self::FAILURE;
        }

        $sources = $from->sources()->with('autoPublishRules')->orderBy('id')->get()
            ->filter(fn (Source $source): bool => $source->type === SourceType::SocialFeedV1)
            ->values();

        foreach ($sources as $source) {
            if (blank($source->setting('query.country'))) {
                $this->warn("⚠ Source '{$source->name}' of '{$from->slug}' does not name its country (query.country); it relies on the feed's default.");
            }
        }

        $brand = $this->brand($from, $slug, $name === '' ? $from->name.' '.mb_strtoupper($country) : $name, $language);

        $this->info("Brand '{$brand->slug}' ({$brand->name}), language {$brand->language}, time zone {$brand->timezone}.");
        $this->table(
            ['source', 'query', 'rules (all off, ask for approval)'],
            $sources->map(fn (Source $source): array => [
                $this->sourceName($source, $country),
                http_build_query($this->query($source, $country)),
                $source->autoPublishRules->map(fn (AutoPublishRule $rule): string => $rule->platform->value)->implode(', ') ?: '-',
            ])->all(),
        );

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run: nothing was made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($brand, $sources, $country): void {
            $brand->save();

            foreach ($sources as $source) {
                $copy = $brand->sources()->create([
                    'name' => $this->sourceName($source, $country),
                    'type' => $source->type,
                    'base_url' => $source->base_url,
                    'auth_type' => $source->auth_type,
                    'secret' => $source->secret,
                    'config' => [
                        ...array_filter((array) $source->config, fn (string|int $key): bool => $key !== 'query' && ! str_starts_with((string) $key, 'query.'), ARRAY_FILTER_USE_KEY),
                        ...array_combine(
                            array_map(fn (string $key): string => 'query.'.$key, array_keys($this->query($source, $country))),
                            array_values($this->query($source, $country)),
                        ),
                    ],
                    'enabled' => false,
                    'auto_publish_backlog' => $source->auto_publish_backlog,
                ]);

                foreach ($source->autoPublishRules as $rule) {
                    $copy->autoPublishRules()->create([
                        'platform' => $rule->platform,
                        'enabled' => false,
                        'requires_approval' => true,
                        'delay_minutes' => $rule->delay_minutes,
                        'daily_cap' => $rule->daily_cap,
                        'format' => $rule->format,
                        'settings' => $rule->settings,
                    ]);
                }
            }
        });

        $this->info('Made. Next: connect its accounts, choose a voice, then switch the sources and rules on in the panel.');

        return self::SUCCESS;
    }

    private function brand(Brand $from, string $slug, string $name, string $language): Brand
    {
        $hashtags = array_values(array_filter(array_map(
            fn (string $tag): string => mb_ltrim(mb_trim($tag), '#'),
            explode(',', (string) $this->option('hashtags')),
        )));

        // The instructions to the writer (tone, rules) carry over; what is published word for word does not.
        $voice = [
            ...array_intersect_key((array) $from->voice, array_flip(['tone', 'rules', 'video_style', 'agent_enabled'])),
            'cta' => $this->text('cta'),
            'pitch' => $this->text('pitch'),
            'activation' => $this->text('activation'),
            'cta_note' => null,
            'hashtags' => $hashtags,
        ];

        // The model and the loudness carry over; the voice, its words' IPA and the pronunciation list are the other language's.
        $voiceover = [
            ...array_intersect_key((array) $from->voiceover, array_flip(['model', 'music', 'speed', 'ipa'])),
            'enabled' => false,
            'voice_id' => null,
            'ipa_auto' => false,
            'words' => [],
            'pronunciations' => [],
            'outro' => null,
        ];

        return new Brand([
            'slug' => $slug,
            'name' => $name,
            'site_url' => $this->text('site-url') ?? $from->site_url,
            'logo_path' => $from->logo_path,
            'colors' => $from->colors,
            'voice' => $voice,
            'posting_windows' => $from->posting_windows,
            'daily_post_limit' => $from->daily_post_limit,
            'auto_lineup' => $from->auto_lineup,
            'lineup_priority' => $from->lineup_priority,
            'audio_tracks' => $from->audio_tracks,
            'voiceover' => $voiceover,
            // A roundup's headline and series are written in the other language; the new brand starts without any.
            'digests' => null,
            'persona' => null,
            'timezone' => (string) $this->option('timezone'),
            'language' => $language,
        ]);
    }

    /**
     * The source's own filters with the country put in place of whatever it said (or did not say).
     *
     * @return array<string, mixed>
     */
    private function query(Source $source, string $country): array
    {
        $query = [];

        foreach ((array) $source->config as $key => $value) {
            if (str_starts_with((string) $key, 'query.')) {
                $query[mb_substr((string) $key, 6)] = $value;
            }
        }

        foreach ((array) ($source->config['query'] ?? []) as $key => $value) {
            $query[(string) $key] = $value;
        }

        return [...$query, 'country' => $country];
    }

    private function sourceName(Source $source, string $country): string
    {
        return "{$source->name}-{$country}";
    }

    private function text(string $option): ?string
    {
        $value = mb_trim((string) $this->option($option));

        return $value === '' ? null : $value;
    }
}
