<?php

declare(strict_types=1);

namespace Tests\Feature\Drafting;

use App\Actions\CreateDraft;
use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StaleDraftContentTest extends TestCase
{
    use RefreshDatabase;

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
