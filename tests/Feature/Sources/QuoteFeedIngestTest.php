<?php

declare(strict_types=1);

namespace Tests\Feature\Sources;

use App\Enums\ContentKind;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use App\Sources\FeedValidator;
use App\Sources\SourceSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A quote comes in through the one adapter every brand uses: `kind: quote` is a value of the contract,
 * not a branch in code, and the hub learns nothing about what the words are.
 */
final class QuoteFeedIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_quote_feed_is_valid_social_feed_v1(): void
    {
        $this->assertSame([], app(FeedValidator::class)->errors($this->feed()));
    }

    public function test_a_sync_stores_the_day_of_each_quote_and_lets_a_late_one_lapse(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC'));
        $source = Source::factory()->for(Brand::factory()->create(['slug' => 'rijec']))->create(['name' => 'biblija-hr', 'config' => ['query.lang' => 'hr']]);
        Http::fake(['*' => Http::response($this->feed())]);

        $result = app(SourceSyncer::class)->sync($source);

        $this->assertSame(2, $result->created);
        // The source's own filter reaches the feed: one adapter, one feed per language.
        Http::assertSent(fn ($request): bool => ($request->data()['lang'] ?? null) === 'hr');

        $today = ContentItem::query()->where('external_id', 'verse:hr:2026-10-13')->firstOrFail();
        $this->assertSame(ContentKind::Quote, $today->kind);
        $this->assertSame('Iv 3,16', $today->subtitle);
        $this->assertSame(1000, $today->priority);
        $this->assertTrue($today->isPostableAt(CarbonImmutable::parse('2026-10-13 16:00:00', 'UTC')), 'the 18:00 post of its own day');

        // What automation picks first is the nearest day, whatever else is waiting.
        $this->assertSame(
            ['verse:hr:2026-10-13', 'verse:hr:2026-10-14'],
            ContentItem::query()->live()->orderByDesc('priority')->pluck('external_id')->all(),
        );

        // The day after, yesterday's verse is neither a candidate nor publishable: a draft nobody approved
        // in time is skipped (PublishVariantJob) instead of going out as today's.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 06:00:00', 'UTC'));

        $this->assertTrue($today->refresh()->isExpired());
        $this->assertFalse($today->isPostableAt(CarbonImmutable::parse('2026-10-14 16:00:00', 'UTC')));
        $this->assertSame(['verse:hr:2026-10-14'], ContentItem::query()->live()->pluck('external_id')->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function feed(): array
    {
        $verse = fn (string $date, string $title, string $reference, int $priority): array => [
            'id' => "verse:hr:{$date}",
            'kind' => 'quote',
            'title' => $title,
            'subtitle' => $reference,
            'url' => "https://biblija.test/hr/{$date}",
            'cta' => ['label' => 'Pročitaj cijelo poglavlje', 'url' => "https://biblija.test/hr/{$date}"],
            'priority' => $priority,
            'tags' => ['evanđelje', 'ljubav'],
            'published_at' => "{$date}T00:00:00Z",
            'expires_at' => "{$date}T21:59:59Z",
            'updated_at' => "{$date}T00:00:00Z",
        ];

        return [
            'version' => '1',
            'brand' => 'rijec',
            'items' => [
                $verse('2026-10-13', 'Jer Bog je tako ljubio svijet da je dao svoga jedinorođenog Sina.', 'Iv 3,16', 1000),
                $verse('2026-10-14', 'Ljubi bližnjega svoga kao samoga sebe.', 'Mk 12,31', 999),
            ],
            'next_cursor' => null,
        ];
    }
}
