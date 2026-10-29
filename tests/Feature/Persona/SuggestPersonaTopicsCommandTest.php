<?php

declare(strict_types=1);

namespace Tests\Feature\Persona;

use App\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeOpenAi;
use Tests\TestCase;

/**
 * The hourly run asks for a character's topics once its local morning has come, and only once that day; a brand that
 * is not a character is never asked about.
 */
final class SuggestPersonaTopicsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openai.api_key', 'test-key');
        Sleep::fake();
        Http::fake(['api.openai.com/*' => Http::response(FakeOpenAi::answer(['topics' => [
            ['title' => 'Wait 24 hours', 'hook' => 'Stop.', 'angle' => 'Wait a day.', 'reason' => 'Fits.'],
        ]]))]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_topics_come_once_the_characters_morning_has_come_and_only_once(): void
    {
        $mia = Brand::factory()->persona(['suggest_at' => '07:00'])->create();
        Brand::factory()->create();

        CarbonImmutable::setTestNow('2026-10-28 06:05:00 Europe/Zagreb');
        $this->artisan('hub:suggest-topics')->assertSuccessful();
        $this->assertSame(0, $mia->topicIdeas()->count());

        CarbonImmutable::setTestNow('2026-10-28 07:05:00 Europe/Zagreb');
        $this->artisan('hub:suggest-topics')->expectsOutputToContain('Wait 24 hours')->assertSuccessful();
        $this->artisan('hub:suggest-topics')->assertSuccessful();

        $this->assertSame(1, $mia->topicIdeas()->count());
        Http::assertSentCount(1);
    }

    public function test_a_character_busy_elsewhere_does_not_stop_the_run_for_the_others(): void
    {
        config()->set('hub.persona.lock_wait_seconds', 0);
        $busy = Brand::factory()->persona(['suggest_at' => '07:00'])->create(['name' => 'Busy']);
        $free = Brand::factory()->persona(['suggest_at' => '07:00'])->create(['name' => 'Free']);
        $lock = Cache::lock("persona-topics:{$busy->id}", 60);
        $lock->get();
        CarbonImmutable::setTestNow('2026-10-28 07:05:00 Europe/Zagreb');

        try {
            $this->artisan('hub:suggest-topics')->assertFailed();
        } finally {
            $lock->release();
        }

        $this->assertSame(0, $busy->topicIdeas()->count());
        $this->assertSame(1, $free->topicIdeas()->count());
    }

    public function test_a_brand_asked_by_name_gets_its_topics_whatever_the_time(): void
    {
        $mia = Brand::factory()->persona(['suggest_at' => '07:00'])->create();
        CarbonImmutable::setTestNow('2026-10-28 05:00:00 Europe/Zagreb');

        $this->artisan('hub:suggest-topics', ['--brand' => $mia->slug])->assertSuccessful();

        $this->assertSame(1, $mia->topicIdeas()->count());
    }
}
