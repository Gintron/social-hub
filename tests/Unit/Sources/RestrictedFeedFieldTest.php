<?php

declare(strict_types=1);

namespace Tests\Unit\Sources;

use App\Sources\ContentItemData;
use App\Sources\FeedValidator;
use Tests\Support\FeedPayload;
use Tests\TestCase;

/**
 * The `restricted` field of Social Feed v1: read from the feed, accepted by the schema, and kept out of
 * the checksum unless set, so that items synced before the field keep their checksum and their drafts.
 */
final class RestrictedFeedFieldTest extends TestCase
{
    public function test_the_source_flag_is_read_from_the_feed_item(): void
    {
        $data = ContentItemData::fromFeedItem(FeedPayload::item('catalog:1', ['kind' => 'deal', 'restricted' => ['alcohol']]));

        $this->assertSame(['alcohol'], $data->restricted);
        $this->assertSame(['alcohol'], $data->toAttributes()['restricted']);
    }

    public function test_an_item_without_the_field_has_no_restrictions(): void
    {
        $this->assertSame([], ContentItemData::fromFeedItem(FeedPayload::item('job:1'))->restricted);
    }

    public function test_an_item_without_the_field_keeps_the_checksum_it_always_had(): void
    {
        $data = ContentItemData::fromFeedItem(FeedPayload::item('job:9'));

        // The formula before the field existed. Changing it would mark every synced item as changed.
        $before = hash('sha256', json_encode([
            $data->kind->value, $data->title, $data->subtitle, $data->bodyText, $data->facts, $data->badges,
            $data->price, $data->cta, $data->url, $data->images, $data->priority, $data->tags,
            $data->publishedAt?->toIso8601ZuluString(), $data->expiresAt?->toIso8601ZuluString(), $data->raw,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->assertSame($before, $data->checksum());
    }

    public function test_setting_the_flag_changes_the_checksum(): void
    {
        $plain = ContentItemData::fromFeedItem(FeedPayload::item('catalog:1', ['kind' => 'deal']));
        $flagged = ContentItemData::fromFeedItem(FeedPayload::item('catalog:1', ['kind' => 'deal', 'restricted' => ['alcohol']]));

        $this->assertNotSame($plain->checksum(), $flagged->checksum(), 'drafts with this item must be marked stale');
    }

    public function test_the_schema_accepts_alcohol_and_refuses_other_goods(): void
    {
        $validator = new FeedValidator;

        $this->assertSame([], $validator->errors(FeedPayload::page([FeedPayload::item('catalog:1', ['kind' => 'catalog', 'restricted' => ['alcohol']])])));
        $this->assertNotSame([], $validator->errors(FeedPayload::page([FeedPayload::item('catalog:1', ['kind' => 'catalog', 'restricted' => ['tobacco']])])));
    }
}
