<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\Source;
use App\Models\Voiceover;
use App\Rendering\ScenePlanner;
use App\Voiceover\Narrator;
use App\Voiceover\VoiceoverException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\FakeSpeech;
use Tests\TestCase;

/**
 * From a draft to spoken clips: what is written, what is checked, what is sent to the voice and what is
 * never paid for twice.
 */
final class NarratorTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        config()->set('elevenlabs.api_key', 'test-key');
        config()->set('elevenlabs.disk', 'local');
        FakeSpeech::fake($this->requests);
    }

    public function test_every_slide_is_spoken_and_what_the_voice_receives_has_no_digits_in_it(): void
    {
        $draft = $this->draft();

        $narration = app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertCount(3, $narration->clips);
        $this->assertSame([
            'Kruh bijeli petsto grama u trgovini Konzum za jedan euro i četrdeset devet centi.',
            'Popust dvadeset pet posto. Vrijedi do tridesetog rujna.',
            'Preuzmi Listo. Dodaj prvi proizvod s letka.',
        ], array_column($this->requests, 'text'));
        $this->assertSame('voice-1', $narration->voiceId);
        $this->assertSame('eleven_multilingual_v2', $narration->model);
        $this->assertSame(-14.0, $narration->musicGainDb);
        $this->assertSame(array_sum(array_map('mb_strlen', array_column($this->requests, 'text'))), $narration->characters());

        foreach ($narration->clips as $clip) {
            $this->assertFileExists($clip->path);
            $this->assertGreaterThan(0.5, $clip->seconds);
        }

        $description = $narration->describe();
        $this->assertSame('ok', $description['status']);
        $this->assertSame('Kruh bijeli 500 g u trgovini Konzum za 1,49 €.', $description['script'][0]['text'], 'the script is kept as written, for a person to read');
    }

    public function test_the_second_video_with_the_same_words_costs_nothing(): void
    {
        $draft = $this->draft();
        $scenes = app(ScenePlanner::class)->forDraft($draft);

        app(Narrator::class)->narrate($draft, $scenes);
        app(Narrator::class)->narrate($draft, $scenes);

        $this->assertCount(3, $this->requests);
        $this->assertSame(3, Voiceover::query()->count());
    }

    public function test_the_brand_closing_line_is_spoken_once_for_every_video_that_follows(): void
    {
        $first = $this->draft('Kruh bijeli 500 g');
        $second = $this->draft('Mlijeko 1 l', $first->brand);

        app(Narrator::class)->narrate($first, app(ScenePlanner::class)->forDraft($first));
        app(Narrator::class)->narrate($second, app(ScenePlanner::class)->forDraft($second));

        // Two hooks; the card (same discount, same date) and the closing line are said once between them.
        $this->assertCount(4, $this->requests);
        $this->assertCount(1, array_filter(array_column($this->requests, 'text'), fn (string $text): bool => str_starts_with($text, 'Preuzmi Listo')));
        $this->assertCount(1, array_filter(array_column($this->requests, 'text'), fn (string $text): bool => str_starts_with($text, 'Popust')));
    }

    public function test_the_brands_pronunciations_are_applied_before_the_voice_hears_a_word(): void
    {
        $draft = $this->draft(voiceover: ['pronunciations' => [['find' => 'Listo', 'say' => 'Lisdo']]]);

        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertSame('Preuzmi Lisdo. Dodaj prvi proizvod s letka.', $this->requests[2]['text']);
    }

    public function test_the_brands_voice_settings_reach_the_request(): void
    {
        $draft = $this->draft(voiceover: ['speed' => 1.1, 'stability' => 0.7, 'model' => 'eleven_v4']);

        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));

        $this->assertSame('eleven_v4', $this->requests[0]['model_id']);
        $this->assertSame(1.1, $this->requests[0]['voice_settings']['speed']);
        $this->assertSame(0.7, $this->requests[0]['voice_settings']['stability']);
    }

    public function test_a_line_that_says_a_price_the_offer_does_not_carry_is_refused_before_anything_is_paid_for(): void
    {
        $draft = $this->draft();

        try {
            app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft), ['Kruh bijeli samo za 0,99 €.', '', '']);
            $this->fail('An invented price must not be spoken.');
        } catch (VoiceoverException $e) {
            $this->assertSame('script_rejected', $e->errorCode);
            $this->assertStringContainsString('0,99 €', $e->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    public function test_a_script_that_says_a_price_the_offer_does_carry_is_spoken(): void
    {
        $draft = $this->draft();

        $narration = app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft), ['Kruh bijeli za 1,49 € umjesto 1,99 €.', '', 'Preuzmi Listo.']);

        $this->assertCount(3, $narration->clips);
        $this->assertNull($narration->clips[1], 'an empty line is a slide left to the music');
        $this->assertSame('Kruh bijeli za jedan euro i četrdeset devet centi umjesto jedan euro i devedeset devet centi.', $this->requests[0]['text']);
    }

    public function test_the_voice_never_reads_out_a_web_address(): void
    {
        $draft = $this->draft();

        $this->expectException(VoiceoverException::class);
        $this->expectExceptionMessage('poveznicu');

        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft), ['Više na https://uselisto.com', '', '']);
    }

    public function test_a_script_with_nothing_to_say_says_nothing(): void
    {
        $draft = $this->draft();

        $this->assertNull(app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft), ['', '  ', '🛒']));
        $this->assertSame([], $this->requests);
    }

    public function test_a_script_longer_than_the_budget_is_refused(): void
    {
        config()->set('elevenlabs.max_characters_per_video', 40);
        $draft = $this->draft();

        try {
            app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));
            $this->fail('The character budget is a fence.');
        } catch (VoiceoverException $e) {
            $this->assertSame('script_rejected', $e->errorCode);
            $this->assertStringContainsString('znakova', $e->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    public function test_a_roundups_headline_is_the_brands_own_text_and_is_not_held_to_the_offers_figures(): void
    {
        $draft = $this->roundup('Akcije do 50 % popusta');
        $scenes = app(ScenePlanner::class)->forDraft($draft);

        $narration = app(Narrator::class)->narrate($draft, $scenes);

        // The cover says the headline as the brand wrote it, then the deepest cut the items themselves carry.
        $this->assertNotNull($narration);
        $this->assertSame('Akcije do pedeset posto popusta. Popusti do dvadeset pet posto.', $this->requests[0]['text']);

        // The exception covers the headline and nothing else on the line: a figure a person adds is still checked.
        try {
            app(Narrator::class)->narrate($draft, $scenes, ['Akcije do 50 % popusta za samo 0,01 €.', '', '', '']);
            $this->fail('A price the offers do not carry must stop the render even next to the headline.');
        } catch (VoiceoverException $e) {
            $this->assertSame('script_rejected', $e->errorCode);
            $this->assertStringContainsString('0,01 €', $e->getMessage());
            $this->assertStringNotContainsString('50 %', $e->getMessage());
        }
    }

    public function test_without_a_key_or_a_voice_it_does_not_try(): void
    {
        $withoutVoice = $this->draft(voiceover: ['voice_id' => null]);

        try {
            app(Narrator::class)->narrate($withoutVoice, app(ScenePlanner::class)->forDraft($withoutVoice));
            $this->fail('A brand with no voice cannot speak.');
        } catch (VoiceoverException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }

        config()->set('elevenlabs.api_key', null);
        $draft = $this->draft();

        $this->expectException(VoiceoverException::class);
        app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));
    }

    public function test_an_api_failure_surfaces_with_its_reason(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'quota_exceeded', 'message' => 'Out of credits']], 401)]);
        $draft = $this->draft();

        try {
            app(Narrator::class)->narrate($draft, app(ScenePlanner::class)->forDraft($draft));
            $this->fail('The quota error must reach the caller.');
        } catch (VoiceoverException $e) {
            $this->assertSame('quota_exceeded', $e->errorCode);
            $this->assertTrue($e->needsAttention());
        }
    }

    private function roundup(string $headline): PostDraft
    {
        $brand = Brand::factory()->create([
            'slug' => 'uselisto-'.Str::lower(Str::random(6)),
            'voice' => ['cta' => 'Preuzmi Listo'],
            'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1'],
        ]);

        $draft = PostDraft::factory()->create(['brand_id' => $brand->id, 'kind' => PostDraft::KIND_DIGEST, 'title' => $headline]);

        foreach (['Kruh bijeli 500 g', 'Mlijeko 1 l'] as $position => $title) {
            $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
                'kind' => ContentKind::Deal,
                'title' => $title,
                'subtitle' => 'Konzum',
                'price' => ['current_cents' => 149, 'old_cents' => 199, 'discount_pct' => 25, 'currency' => 'EUR', 'unit_label' => null],
                'facts' => [],
                'badges' => [],
            ]);
            $draft->contentItems()->attach($item->id, ['position' => $position, 'checksum' => $item->checksum]);
        }

        return $draft->load(['brand', 'contentItems']);
    }

    /**
     * @param  array<string, mixed>  $voiceover
     */
    private function draft(string $title = 'Kruh bijeli 500 g', ?Brand $brand = null, array $voiceover = []): PostDraft
    {
        $brand ??= Brand::factory()->create([
            'slug' => 'uselisto-'.Str::lower(Str::random(6)),
            'voice' => ['cta' => 'Preuzmi Listo', 'activation' => 'Dodaj prvi proizvod s letka.'],
            'voiceover' => ['enabled' => true, 'voice_id' => 'voice-1', ...$voiceover],
        ]);

        $item = ContentItem::factory()->for(Source::factory()->for($brand))->for($brand)->create([
            'kind' => ContentKind::Deal,
            'title' => $title,
            'subtitle' => 'Konzum',
            'price' => ['current_cents' => 149, 'old_cents' => 199, 'discount_pct' => 25, 'currency' => 'EUR', 'unit_label' => null],
            'facts' => [['label' => 'NAJNIŽA U 30 DANA', 'value' => '1,89 €']],
            'badges' => ['−25 %'],
            'expires_at' => '2026-09-30T23:59:59Z',
        ]);
        $draft = PostDraft::factory()->create(['brand_id' => $brand->id, 'kind' => PostDraft::KIND_SINGLE, 'title' => $title]);
        $draft->contentItems()->attach($item->id, ['position' => 0, 'checksum' => $item->checksum]);

        return $draft->load(['brand', 'contentItems']);
    }
}
