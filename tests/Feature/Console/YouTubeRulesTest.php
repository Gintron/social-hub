<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Enums\SourceType;
use App\Models\Brand;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A connected YouTube channel posts nothing until its sources have a YouTube rule; the command adds it where the
 * source already posts automatically, and only asks for approval unless told not to.
 */
final class YouTubeRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_source_that_posts_automatically_gets_a_youtube_rule_modelled_on_its_tiktok_one(): void
    {
        [$deals, $catalogs, $idle] = $this->listo();

        $this->artisan('hub:youtube-rules', ['brands' => ['uselisto']])->assertSuccessful();

        $rule = $deals->autoPublishRules()->where('platform', Platform::YouTube->value)->firstOrFail();
        $this->assertTrue($rule->enabled);
        $this->assertTrue($rule->requires_approval);
        $this->assertSame(30, $rule->delay_minutes);
        $this->assertSame(5, $rule->daily_cap);
        $this->assertSame(ContentFormat::Video, $rule->format);
        $this->assertSame(['voiceover' => 'on'], $rule->settings);

        $this->assertSame(3, $catalogs->autoPublishRules()->where('platform', Platform::YouTube->value)->firstOrFail()->daily_cap, 'no TikTok rule: the first enabled one');
        $this->assertSame(0, $idle->autoPublishRules()->count(), 'a source that posts nowhere stays as it is');
    }

    public function test_it_is_repeatable_and_a_dry_run_makes_nothing(): void
    {
        [$deals] = $this->listo();

        $this->artisan('hub:youtube-rules', ['brands' => ['uselisto'], '--dry-run' => true])->assertSuccessful();
        $this->assertFalse($deals->autoPublishRules()->where('platform', Platform::YouTube->value)->exists());

        $this->artisan('hub:youtube-rules', ['brands' => ['uselisto'], '--no-approval' => true])->assertSuccessful();
        $this->artisan('hub:youtube-rules', ['brands' => ['uselisto']])->assertSuccessful();

        $rules = $deals->autoPublishRules()->where('platform', Platform::YouTube->value)->get();
        $this->assertCount(1, $rules);
        $this->assertFalse($rules->first()->requires_approval);
    }

    public function test_an_unknown_brand_is_refused(): void
    {
        $this->artisan('hub:youtube-rules', ['brands' => ['nope']])->assertFailed();
    }

    /**
     * @return array{0: Source, 1: Source, 2: Source}
     */
    private function listo(): array
    {
        $brand = Brand::factory()->create(['slug' => 'uselisto']);

        $deals = Source::factory()->for($brand)->create(['name' => 'listo', 'type' => SourceType::SocialFeedV1]);
        $deals->autoPublishRules()->create(['platform' => Platform::FacebookPage, 'enabled' => true, 'requires_approval' => false, 'delay_minutes' => 0, 'daily_cap' => 9]);
        $deals->autoPublishRules()->create(['platform' => Platform::TikTok, 'enabled' => true, 'requires_approval' => false, 'delay_minutes' => 30, 'daily_cap' => 5, 'settings' => ['voiceover' => 'on']]);

        $catalogs = Source::factory()->for($brand)->create(['name' => 'listo-katalozi', 'type' => SourceType::SocialFeedV1]);
        $catalogs->autoPublishRules()->create(['platform' => Platform::FacebookPage, 'enabled' => true, 'requires_approval' => false, 'delay_minutes' => 0, 'daily_cap' => 3]);

        $idle = Source::factory()->for($brand)->create(['name' => 'Gotovi videi', 'type' => SourceType::Webhook]);

        return [$deals, $catalogs, $idle];
    }
}
