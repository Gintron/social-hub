<?php

declare(strict_types=1);

namespace Tests\Feature\Sources;

use App\Enums\AuthType;
use App\Models\Brand;
use App\Models\Source;
use App\Sources\Exceptions\FeedValidationException;
use App\Sources\Exceptions\SourceRequestException;
use App\Sources\SocialFeedV1Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FeedPayload;
use Tests\TestCase;

final class SocialFeedV1SourceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://site.test/api/social-feed';

    public function test_follows_cursor_pagination_and_sends_bearer_token(): void
    {
        Http::fake(function (Request $request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ($query['cursor'] ?? null) {
                null => Http::response(FeedPayload::page([FeedPayload::item('job:1'), FeedPayload::item('job:2')], 'c2')),
                'c2' => Http::response(FeedPayload::page([FeedPayload::item('job:3')], null)),
                default => Http::response('unexpected', 500),
            };
        });

        $source = $this->source();

        $items = iterator_to_array(app(SocialFeedV1Source::class)->fetch($source, now()->toImmutable()->subDay()), false);

        $this->assertSame(['job:1', 'job:2', 'job:3'], array_map(fn ($item) => $item->externalId, $items));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token')
            && str_contains($request->url(), 'since=')
            && str_contains($request->url(), 'limit=50'));
        Http::assertSentCount(2);
    }

    public function test_sends_api_key_header_when_configured(): void
    {
        Http::fake([self::URL.'*' => Http::response(FeedPayload::page([]))]);

        $source = $this->source(['auth_type' => AuthType::ApiKey, 'secret' => 'k-123']);

        iterator_to_array(app(SocialFeedV1Source::class)->fetch($source, null), false);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Api-Key', 'k-123') && ! $request->hasHeader('Authorization'));
    }

    public function test_rejects_payload_that_violates_the_schema(): void
    {
        Http::fake([self::URL.'*' => Http::response(FeedPayload::page([
            FeedPayload::item('job:1', ['url' => 'not-a-url', 'kind' => 'banana']),
        ]))]);

        $this->expectException(FeedValidationException::class);

        iterator_to_array(app(SocialFeedV1Source::class)->fetch($this->source(), null), false);
    }

    public function test_rejects_non_2xx_responses(): void
    {
        Http::fake([self::URL.'*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        try {
            iterator_to_array(app(SocialFeedV1Source::class)->fetch($this->source(), null), false);
            $this->fail('Expected SourceRequestException');
        } catch (SourceRequestException $e) {
            $this->assertSame(401, $e->status);
        }
    }

    public function test_maps_item_fields(): void
    {
        Http::fake([self::URL.'*' => Http::response(FeedPayload::page([FeedPayload::item('job:9')]))]);

        [$item] = iterator_to_array(app(SocialFeedV1Source::class)->fetch($this->source(), null), false);

        $this->assertSame('job:9', $item->externalId);
        $this->assertSame('job', $item->kind->value);
        $this->assertSame('Konobar/ica', $item->title);
        $this->assertSame('Hotel Adriatic', $item->subtitle);
        $this->assertSame('SPLIT', $item->facts[0]['value']);
        $this->assertSame(['Sezonski posao', 'Smještaj'], $item->badges);
        $this->assertSame(700, $item->price['current_cents']);
        $this->assertSame('https://example.test/images/1.webp', $item->images[0]['url']);
        $this->assertSame('primary', $item->images[0]['role']);
        $this->assertSame(4, $item->priority);
        $this->assertSame('2026-10-05T00:00:00Z', $item->expiresAt?->toIso8601ZuluString());
        $this->assertSame('2026-09-05T07:10:00Z', $item->updatedAt->toIso8601ZuluString());
        $this->assertSame('premium', $item->raw['tier']);
        $this->assertSame(64, mb_strlen($item->checksum()));
    }

    public function test_test_connection_reports_warnings(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(FeedPayload::page([
                FeedPayload::item('job:1', ['images' => [['url' => 'http://example.test/plain.jpg', 'role' => 'primary']]]),
                FeedPayload::item('job:1'),
            ], null, 'other-brand')),
            'http://example.test/plain.jpg' => Http::response('', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = app(SocialFeedV1Source::class)->test($this->source());

        $this->assertSame('other-brand', $result->brand);
        $this->assertSame(2, $result->itemCount);
        $this->assertFalse($result->ok());
        $this->assertStringContainsString("brand 'other-brand'", implode("\n", $result->warnings));
        $this->assertStringContainsString('Duplicate item ids', implode("\n", $result->warnings));
        $this->assertStringContainsString('Non-https URL', implode("\n", $result->warnings));
        $this->assertStringContainsString('Content-Type text/html', implode("\n", $result->warnings));
    }

    public function test_test_connection_is_clean_for_a_good_feed(): void
    {
        Http::fake([
            self::URL.'*' => Http::response(FeedPayload::page([FeedPayload::item('job:1')])),
            'https://example.test/images/1.webp' => Http::response('', 200, ['Content-Type' => 'image/webp']),
        ]);

        $result = app(SocialFeedV1Source::class)->test($this->source());

        $this->assertTrue($result->ok(), implode("\n", $result->warnings));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function test_sends_configured_filter_parameters_from_flat_config_keys(): void
    {
        Http::fake([self::URL.'*' => Http::response(FeedPayload::page([]))]);

        $source = $this->source(['config' => ['query.country' => 'hr', 'query.min_discount' => '40']]);

        iterator_to_array(app(SocialFeedV1Source::class)->fetch($source, null), false);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['country'] ?? null) === 'hr'
                && ($query['min_discount'] ?? null) === '40'
                && ($query['limit'] ?? null) === '50';
        });
    }

    public function test_keeps_query_parameters_written_into_the_base_url(): void
    {
        Http::fake([self::URL.'*' => Http::response(FeedPayload::page([]))]);

        $source = $this->source(['base_url' => self::URL.'?country=hr']);

        iterator_to_array(app(SocialFeedV1Source::class)->fetch($source, null), false);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['country'] ?? null) === 'hr' && ($query['limit'] ?? null) === '50';
        });
    }

    public function test_contract_parameters_win_over_configured_ones(): void
    {
        Http::fake([self::URL.'*' => Http::response(FeedPayload::page([]))]);

        $source = $this->source(['config' => ['page_limit' => 10, 'query.limit' => '999']]);

        iterator_to_array(app(SocialFeedV1Source::class)->fetch($source, null), false);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['limit'] ?? null) === '10';
        });
    }

    public function test_connection_test_uses_the_same_filter_parameters(): void
    {
        Http::fake([self::URL.'*' => Http::response(FeedPayload::page([]))]);

        $source = $this->source(['config' => ['query.country' => 'hr']]);

        app(SocialFeedV1Source::class)->test($source);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'country=hr'));
    }

    private function source(array $overrides = []): Source
    {
        $brand = Brand::factory()->create(['slug' => 'studentski-poslovi']);

        return Source::factory()->for($brand)->create(array_replace([
            'base_url' => self::URL,
            'auth_type' => AuthType::Bearer,
            'secret' => 'secret-token',
        ], $overrides));
    }
}
