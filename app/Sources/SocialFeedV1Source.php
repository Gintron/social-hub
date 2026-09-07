<?php

declare(strict_types=1);

namespace App\Sources;

use App\Enums\AuthType;
use App\Models\Source;
use App\Sources\Contracts\ContentSource;
use App\Sources\Exceptions\FeedValidationException;
use App\Sources\Exceptions\SourceException;
use App\Sources\Exceptions\SourceRequestException;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one adapter every brand uses: pulls `GET {base_url}?since&cursor&limit` per docs/social-feed-v1.md.
 */
final class SocialFeedV1Source implements ContentSource
{
    public function __construct(private readonly FeedValidator $validator) {}

    public function fetch(Source $source, ?CarbonImmutable $since): Generator
    {
        $limit = (int) $source->setting('page_limit', config('hub.sync.page_limit', 50));
        $maxPages = (int) config('hub.sync.max_pages', 50);
        $cursor = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $payload = $this->page($source, $since, $cursor, $limit);

            foreach ($payload['items'] as $item) {
                yield ContentItemData::fromFeedItem($item);
            }

            $cursor = $payload['next_cursor'] ?? null;

            if ($cursor === null || $cursor === '') {
                return;
            }
        }

        throw new SourceException("Source {$source->name}: more than {$maxPages} pages in one sync; refusing to continue.");
    }

    public function test(Source $source): SourceTestResult
    {
        $payload = $this->page($source, null, null, 3);

        $items = array_map(ContentItemData::fromFeedItem(...), $payload['items']);
        $warnings = [];

        $expectedBrand = $source->brand?->slug;
        if ($expectedBrand !== null && $payload['brand'] !== $expectedBrand) {
            $warnings[] = "Feed reports brand '{$payload['brand']}', hub source belongs to brand '{$expectedBrand}'.";
        }

        $ids = array_map(fn (ContentItemData $item): string => $item->externalId, $items);
        if (count($ids) !== count(array_unique($ids))) {
            $warnings[] = 'Duplicate item ids inside one page.';
        }

        foreach ($items as $item) {
            foreach ([$item->url, ...array_column($item->images, 'url')] as $url) {
                if (! str_starts_with($url, 'https://')) {
                    $warnings[] = "Non-https URL: {$url}";
                }
            }
        }

        if ($items !== [] && $items[0]->images !== []) {
            $warnings = [...$warnings, ...$this->checkImage($items[0]->images[0]['url'])];
        } elseif ($items !== []) {
            $warnings[] = 'First item has no images; Instagram posts will need a rendered template.';
        }

        if ($items === []) {
            $warnings[] = 'Feed returned no items (fine if the site has nothing to publish right now).';
        }

        return new SourceTestResult(
            brand: (string) $payload['brand'],
            itemCount: count($items),
            samples: $items,
            warnings: array_values(array_unique($warnings)),
            nextCursor: $payload['next_cursor'] ?? null,
        );
    }

    /**
     * @return array{version: string, brand: string, items: list<array<string, mixed>>, next_cursor: string|null}
     */
    private function page(Source $source, ?CarbonImmutable $since, ?string $cursor, int $limit): array
    {
        $url = (string) $source->base_url;

        if ($url === '') {
            throw new SourceException("Source {$source->name} has no base_url.");
        }

        $query = array_filter([
            'since' => $since?->toIso8601ZuluString(),
            'cursor' => $cursor,
            'limit' => $limit,
        ], fn ($value): bool => $value !== null);

        try {
            $response = $this->request($source)->get($url, $query);
        } catch (ConnectionException $e) {
            throw new SourceRequestException("Could not reach {$url}: {$e->getMessage()}", null, $url);
        }

        if (! $response->successful()) {
            throw new SourceRequestException(
                "{$url} answered HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300),
                $response->status(),
                $url,
            );
        }

        $payload = $this->decode($response, $url);

        $errors = $this->validator->errors($payload);
        if ($errors !== []) {
            throw new FeedValidationException($errors, $url);
        }

        /** @var array{version: string, brand: string, items: list<array<string, mixed>>, next_cursor: string|null} $payload */
        return $payload;
    }

    private function request(Source $source): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) config('hub.sync.http_timeout', 20))
            ->withUserAgent('social-hub/1.0 (+Social Feed v1)');

        $secret = (string) $source->secret;

        return match ($source->auth_type) {
            AuthType::Bearer => $request->withToken($secret),
            AuthType::ApiKey => $request->withHeaders(['X-Api-Key' => $secret]),
            AuthType::None => $request,
            AuthType::Hmac => throw new SourceException('HMAC auth is for webhook (push) sources, not for pulling a feed.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $url): array
    {
        try {
            $payload = $response->json();
        } catch (Throwable) {
            $payload = null;
        }

        if (! is_array($payload)) {
            throw new FeedValidationException(['Response body is not a JSON object.'], $url);
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function checkImage(string $url): array
    {
        try {
            $head = Http::timeout(10)->head($url);
        } catch (ConnectionException $e) {
            return ["First image not reachable ({$url}): {$e->getMessage()}"];
        }

        if (! $head->successful()) {
            return ["First image {$url} answered HTTP {$head->status()}."];
        }

        $type = (string) $head->header('Content-Type');
        if ($type !== '' && ! str_starts_with($type, 'image/')) {
            return ["First image {$url} has Content-Type {$type}, expected image/*."];
        }

        return [];
    }
}
