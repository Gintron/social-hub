<?php

declare(strict_types=1);

use App\Enums\ContentKind;
use App\Models\ContentItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ContentItem::publicationChecksum() now counts `raw.demo`: the products, prices, sum and dates a new-catalog video
 * prints. A draft made before holds the checksum without it, so left alone every unpublished catalog draft would be
 * flagged as changed on the next publish for nothing but the new formula.
 *
 * A draft whose stored checksum still equals what its item gives without the block was made from the item as it is
 * now: it is stamped with the new checksum and stays good. One that does not equal it was made from an item that
 * has changed since; it keeps the old checksum, which is no longer the item's, and stays stale, as it should.
 *
 * What cannot be known is whether the block alone changed after a draft was made: that was never recorded, and is
 * the very thing the old checksum could not see. Those drafts are no worse off than they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        ContentItem::query()
            ->where('kind', ContentKind::Catalog->value)
            ->whereNotNull('raw')
            ->chunkById(100, function ($items): void {
                foreach ($items as $item) {
                    $raw = (array) $item->raw;

                    if (! array_key_exists('demo', $raw)) {
                        continue;
                    }

                    // The checksum as it was is the checksum of the item without the block.
                    $before = $item->replicate();
                    $before->raw = array_diff_key($raw, ['demo' => true]);

                    DB::table('post_draft_content_items')
                        ->where('content_item_id', $item->id)
                        ->where('publication_checksum', $before->publicationChecksum())
                        ->update(['publication_checksum' => $item->publicationChecksum()]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: a checksum is a fingerprint of the item, and the old one is the item without the block.
    }
};
