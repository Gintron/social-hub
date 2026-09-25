<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use App\Enums\AuthType;
use App\Enums\Platform;
use App\Enums\SourceType;
use App\Models\AutoPublishRule;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FeedPayload;
use Tests\TestCase;

/**
 * Push ingest: the same payload as the pull feed, signed instead of fetched.
 */
final class IngestControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-tajna';

    protected function setUp(): void
    {
        parent::setUp();

        // Auto-publish checks each item's page before scheduling it.
        Http::fake(['example.test/*' => Http::response()]);
    }

    public function test_a_signed_payload_creates_items(): void
    {
        $source = $this->source();
        $payload = FeedPayload::page([FeedPayload::item('job:1'), FeedPayload::item('job:2', ['title' => 'Kuhar/ica'])], brand: $source->brand->slug);

        $this->send($source, $payload)
            ->assertOk()
            ->assertJson(['status' => 'ok', 'created' => 2, 'updated' => 0]);

        $this->assertSame(2, ContentItem::query()->count());

        $item = ContentItem::query()->where('external_id', 'job:1')->firstOrFail();
        $this->assertSame($source->brand_id, $item->brand_id);
        $this->assertSame('Konobar/ica', $item->title);
        $this->assertNotNull($source->refresh()->last_synced_at);
    }

    public function test_resending_the_same_payload_changes_nothing(): void
    {
        $source = $this->source();
        $payload = FeedPayload::page([FeedPayload::item('job:1')], brand: $source->brand->slug);

        $this->send($source, $payload)->assertOk();
        $this->send($source, $payload)->assertJson(['created' => 0, 'updated' => 0]);

        $changed = FeedPayload::page([FeedPayload::item('job:1', ['title' => 'Konobar/ica — hitno'])], brand: $source->brand->slug);
        $this->send($source, $changed)->assertJson(['created' => 0, 'updated' => 1]);

        $this->assertSame(1, ContentItem::query()->count());
        $this->assertSame('Konobar/ica — hitno', ContentItem::query()->firstOrFail()->title);
    }

    public function test_a_wrong_or_missing_signature_is_refused(): void
    {
        $source = $this->source();
        $payload = FeedPayload::page([FeedPayload::item('job:1')], brand: $source->brand->slug);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', "/api/ingest/{$source->name}", [], [], [], $this->headers(''), $body)->assertStatus(401);
        $this->call('POST', "/api/ingest/{$source->name}", [], [], [], $this->headers('sha256=deadbeef'), $body)->assertStatus(401);

        // Right signature, but for a different body.
        $this->call('POST', "/api/ingest/{$source->name}", [], [], [], $this->headers(hash_hmac('sha256', '{}', self::SECRET)), $body)->assertStatus(401);

        $this->assertSame(0, ContentItem::query()->count());
    }

    public function test_a_payload_that_breaks_the_contract_is_rejected_with_details(): void
    {
        $source = $this->source();

        $this->send($source, FeedPayload::page([FeedPayload::item('job:1', ['url' => 'nije-url'])], brand: $source->brand->slug))
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_payload')
            ->assertJsonStructure(['details']);

        $this->assertSame(0, ContentItem::query()->count());
    }

    public function test_an_unknown_or_disabled_source_looks_the_same_from_outside(): void
    {
        $source = $this->source();
        $payload = FeedPayload::page([FeedPayload::item('job:1')], brand: $source->brand->slug);

        $this->send($source, $payload, name: 'ne-postoji')->assertStatus(404);

        $source->forceFill(['enabled' => false])->save();
        $this->send($source, $payload)->assertStatus(404);

        // A pull source must not accept pushes either: its secret is a read token, not a signing key.
        $pull = Source::factory()->for($source->brand)->create(['type' => SourceType::SocialFeedV1, 'secret' => self::SECRET]);
        $this->send($pull, $payload)->assertStatus(404);
    }

    public function test_pushed_items_go_through_the_same_auto_publish_rules(): void
    {
        Queue::fake();

        $source = $this->source();
        SocialAccount::factory()->for($source->brand)->create();
        AutoPublishRule::query()->create([
            'source_id' => $source->id,
            'platform' => Platform::FacebookPage,
            'enabled' => true,
            'delay_minutes' => 0,
        ]);

        $this->send($source, FeedPayload::page([FeedPayload::item('job:1')], brand: $source->brand->slug))->assertOk();

        $this->assertSame(1, PostDraft::query()->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(Source $source, array $payload, ?string $name = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, self::SECRET);

        return $this->call('POST', '/api/ingest/'.($name ?? $source->name), [], [], [], $this->headers($signature), $body);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $signature): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature,
        ];
    }

    private function source(): Source
    {
        $brand = Brand::factory()->create([
            'slug' => 'studentski-poslovi',
            'posting_windows' => [['from' => '00:00', 'to' => '23:59']],
        ]);

        return Source::factory()->for($brand)->create([
            'name' => 'studentski-poslovi-push',
            'type' => SourceType::Webhook,
            'auth_type' => AuthType::Hmac,
            'base_url' => null,
            'secret' => self::SECRET,
        ]);
    }
}
