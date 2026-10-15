<?php

declare(strict_types=1);

namespace Tests\Feature\Drafting;

use App\Actions\CreateDraft;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Support\MakesCatalogItems;
use Tests\TestCase;

final class StaleDraftContentTest extends TestCase
{
    use MakesCatalogItems;
    use RefreshDatabase;

    private const STAMP_DEMO_MIGRATION = '2026_10_15_000001_stamp_catalog_demo_into_publication_checksums.php';

    public function test_new_drafts_snapshot_material_content_and_ignore_feed_bookkeeping(): void
    {
        [$item, $draft] = $this->draft();
        $this->assertSame($item->publicationChecksum(), $draft->contentItems->first()->pivot->publication_checksum);

        $item->update(['priority' => 99, 'source_updated_at' => now()->addHour(), 'checksum' => hash('sha256', 'operational change'), 'raw' => ['offer_id' => 999]]);

        $this->assertTrue($draft->load('contentItems')->staleItems()->isEmpty());
    }

    public function test_a_changed_price_makes_the_draft_stale(): void
    {
        [$item, $draft] = $this->draft();
        $item->update(['price' => ['current_cents' => 999, 'currency' => 'EUR']]);

        $this->assertCount(1, $draft->load('contentItems')->staleItems());
    }

    public function test_relevant_raw_data_and_expiry_change_the_material_checksum(): void
    {
        $item = ContentItem::factory()->create();
        $baseline = $item->publicationChecksum();
        $item->raw = ['offer_id' => 3, 'emoji' => '💼'];
        $this->assertNotSame($baseline, $item->publicationChecksum());
        $item->refresh();
        $item->expires_at = $item->expires_at->addDay();
        $this->assertNotSame($baseline, $item->publicationChecksum());
    }

    public function test_drafts_from_before_the_material_checksum_fall_back_to_the_whole_item_checksum(): void
    {
        [$item, $draft] = $this->draft();
        $draft->contentItems()->updateExistingPivot($item->id, ['publication_checksum' => null]);
        $this->assertTrue($draft->load('contentItems')->staleItems()->isEmpty());

        $item->update(['checksum' => hash('sha256', 'legacy change')]);
        $this->assertCount(1, $draft->load('contentItems')->staleItems());
    }

    public function test_a_catalog_video_goes_stale_when_the_source_changes_what_it_prints(): void
    {
        $item = $this->catalogItem();
        $account = SocialAccount::factory()->create(['brand_id' => $item->brand_id, 'platform' => Platform::FacebookPage]);
        $draft = app(CreateDraft::class)->execute($item, [$account], render: false);

        // The feed's bookkeeping is not what the video prints.
        $item->update(['raw' => [...$item->raw, 'offer_id' => 5]]);
        $this->assertTrue($draft->load('contentItems')->staleItems()->isEmpty());

        // A price the source corrected is: the video was made with the old one.
        $demo = $item->raw['demo'];
        $demo['taps'][0]['price_cents'] += 20;
        $demo['total_cents'] += 20;
        $item->update(['raw' => [...$item->raw, 'demo' => $demo]]);

        $this->assertCount(1, $draft->load('contentItems')->staleItems());
    }

    public function test_an_item_read_back_from_the_database_hashes_like_the_one_just_saved(): void
    {
        // A leaflet's tap points are doubles with 17 digits, and the JSON column gives back fewer of them.
        $item = $this->catalogItem();

        $this->assertSame($item->publicationChecksum(), ContentItem::query()->findOrFail($item->id)->publicationChecksum());
    }

    public function test_a_draft_made_before_the_demo_counted_stays_good_while_its_item_has_not_changed(): void
    {
        $brand = Brand::factory()->create(['slug' => 'uselisto', 'name' => 'Listo', 'site_url' => 'https://uselisto.com', 'voice' => ['cta' => 'Preuzmi Listo']]);
        $unchanged = $this->catalogItem($brand);
        $changed = $this->catalogItem($brand);
        $drafts = [];

        foreach ([$unchanged, $changed] as $item) {
            $account = SocialAccount::factory()->create(['brand_id' => $item->brand_id, 'platform' => Platform::FacebookPage]);
            $draft = app(CreateDraft::class)->execute($item, [$account], render: false);
            // What this draft held before raw.demo was part of the checksum.
            $draft->contentItems()->updateExistingPivot($item->id, ['publication_checksum' => $this->checksumWithoutDemo($item)]);
            $drafts[$item->id] = $draft;
        }

        // The second item's leaflet was replaced after its draft was made: its dates moved, which the old checksum did see.
        $changed->update(['expires_at' => $changed->expires_at->addDays(3)]);

        $this->stampDemoIntoChecksums();

        // Nothing happened to the first item: its draft would have been flagged for no reason by a changed formula alone.
        $this->assertTrue($drafts[$unchanged->id]->load('contentItems')->staleItems()->isEmpty());
        $this->assertSame($unchanged->fresh()->publicationChecksum(), $drafts[$unchanged->id]->contentItems()->first()->pivot->publication_checksum);
        // The second changed after its draft was made, and still says so.
        $this->assertCount(1, $drafts[$changed->id]->load('contentItems')->staleItems());
        $this->assertNotSame($changed->fresh()->publicationChecksum(), $drafts[$changed->id]->contentItems()->first()->pivot->publication_checksum);

        // And from now on the block is watched for the first one too.
        $demo = $unchanged->raw['demo'];
        $demo['total_cents'] += 1;
        $unchanged->update(['raw' => [...$unchanged->raw, 'demo' => $demo]]);
        $this->assertCount(1, $drafts[$unchanged->id]->load('contentItems')->staleItems());
    }

    private function checksumWithoutDemo(ContentItem $item): string
    {
        $without = $item->replicate();
        $without->raw = array_diff_key((array) $item->raw, ['demo' => true]);

        return $without->publicationChecksum();
    }

    private function stampDemoIntoChecksums(): void
    {
        // The migrator already required the file; asking it again returns that very migration.
        $migrator = app('migrator');
        $migration = (new ReflectionMethod($migrator, 'resolvePath'))->invoke($migrator, database_path('migrations/'.self::STAMP_DEMO_MIGRATION));

        $migration->up();
    }

    /** @return array{ContentItem, PostDraft} */
    private function draft(): array
    {
        $item = ContentItem::factory()->create();
        $account = SocialAccount::factory()->create(['brand_id' => $item->brand_id, 'platform' => Platform::FacebookPage]);
        $draft = app(CreateDraft::class)->execute($item, [$account], render: false, channelOptions: [
            Platform::FacebookPage->value => ['format' => ContentFormat::Link],
        ]);

        return [$item, $draft];
    }
}
