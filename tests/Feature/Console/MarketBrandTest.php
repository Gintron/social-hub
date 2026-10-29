<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Platform;
use App\Enums\SourceType;
use App\Models\Brand;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A brand for another market starts as its own unit with nothing switched on: the same feeds asked for its country,
 * the same rules off and asking for approval, and none of the other language's published words.
 */
final class MarketBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_brand_gets_its_own_sources_and_rules_and_nothing_goes_out(): void
    {
        $listo = $this->listo();

        $this->artisan('hub:market-brand', [
            'from' => 'uselisto', 'slug' => 'uselisto-at', '--name' => 'Listo Österreich', '--country' => 'at',
            '--site-url' => 'https://uselisto.com/at', '--cta' => 'Hol dir Listo', '--hashtags' => 'angebote, #prospekt',
        ])->assertSuccessful();

        $at = Brand::query()->where('slug', 'uselisto-at')->firstOrFail();
        $this->assertSame('de', $at->languageCode());
        $this->assertSame('Europe/Vienna', $at->timezone);
        $this->assertSame('https://uselisto.com/at', $at->site_url);
        $this->assertEquals($listo->posting_windows, $at->posting_windows);
        $this->assertSame('Hol dir Listo', $at->voice['cta']);
        $this->assertSame(['angebote', 'prospekt'], $at->voice['hashtags']);
        $this->assertNull($at->voice['pitch'], 'the Croatian pitch is not copied');
        $this->assertSame('Kratko, na „ti“.', $at->voice['tone'], 'the instructions to the writer are');
        $this->assertFalse($at->voiceover['enabled']);
        $this->assertNull($at->voiceover['voice_id']);
        $this->assertSame([], $at->voiceover['pronunciations']);
        $this->assertNull($at->digests);

        $sources = $at->sources()->with('autoPublishRules')->orderBy('id')->get();
        $this->assertSame(['listo-at', 'listo-katalozi-at'], $sources->pluck('name')->all(), 'the webhook source stays behind');
        $this->assertEquals(['query.min_discount' => '35', 'query.country' => 'at'], $sources[0]->config);
        $this->assertEquals(['query.kind' => 'catalog', 'query.country' => 'at'], $sources[1]->config);
        $this->assertSame('key', $sources[0]->secret);
        $this->assertTrue($sources->every(fn (Source $source): bool => ! $source->enabled));

        $rules = $sources->flatMap->autoPublishRules;
        $this->assertCount(2, $rules);
        $this->assertTrue($rules->every(fn ($rule): bool => ! $rule->enabled && $rule->requires_approval));

        // The Croatian brand is as it was.
        $this->assertSame(3, $listo->sources()->count());
        $this->assertTrue($listo->sources()->where('name', 'listo')->firstOrFail()->autoPublishRules()->firstOrFail()->enabled);
    }

    public function test_a_dry_run_makes_nothing_and_an_existing_slug_is_refused(): void
    {
        $this->listo();

        $this->artisan('hub:market-brand', ['from' => 'uselisto', 'slug' => 'uselisto-at', '--country' => 'at', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
        $this->assertFalse(Brand::query()->where('slug', 'uselisto-at')->exists());

        $this->artisan('hub:market-brand', ['from' => 'uselisto', 'slug' => 'uselisto', '--country' => 'at'])->assertFailed();
        $this->artisan('hub:market-brand', ['from' => 'uselisto', 'slug' => 'uselisto-at', '--country' => 'austria'])->assertFailed();
        $this->assertSame(1, Brand::query()->count());
    }

    public function test_a_source_that_does_not_name_its_country_is_pointed_out(): void
    {
        $this->listo();

        $this->artisan('hub:market-brand', ['from' => 'uselisto', 'slug' => 'uselisto-at', '--country' => 'at', '--dry-run' => true])
            ->expectsOutputToContain("Source 'listo-katalozi' of 'uselisto' does not name its country");
    }

    private function listo(): Brand
    {
        $brand = Brand::factory()->create([
            'slug' => 'uselisto',
            'name' => 'Listo',
            'posting_windows' => [['from' => '07:30', 'to' => '09:00']],
            'voice' => ['cta' => 'Preuzmi Listo', 'pitch' => 'Letak → tvoja lista.', 'tone' => 'Kratko, na „ti“.', 'hashtags' => ['akcije']],
            'voiceover' => ['enabled' => true, 'voice_id' => 'luka', 'model' => 'eleven_v4', 'pronunciations' => [['find' => 'SPAR', 'say' => 'špar']]],
        ]);

        $deals = Source::factory()->for($brand)->create([
            'name' => 'listo', 'type' => SourceType::SocialFeedV1, 'secret' => 'key',
            'config' => ['query.country' => 'hr', 'query.min_discount' => '35'],
        ]);
        $deals->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true, 'requires_approval' => false, 'delay_minutes' => 30, 'daily_cap' => 5]);

        $catalogs = Source::factory()->for($brand)->create([
            'name' => 'listo-katalozi', 'type' => SourceType::SocialFeedV1, 'secret' => 'key',
            'config' => ['query.kind' => 'catalog'],
        ]);
        $catalogs->autoPublishRules()->create(['platform' => Platform::FacebookPage, 'enabled' => true, 'requires_approval' => false, 'delay_minutes' => 0, 'daily_cap' => 3]);

        Source::factory()->for($brand)->create(['name' => 'Gotovi videi', 'type' => SourceType::Webhook, 'config' => null]);

        return $brand;
    }
}
