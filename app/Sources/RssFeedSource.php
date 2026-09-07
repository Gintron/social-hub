<?php

declare(strict_types=1);

namespace App\Sources;

use App\Enums\ContentKind;
use App\Models\Source;
use App\Sources\Contracts\ContentSource;
use App\Sources\Exceptions\FeedValidationException;
use App\Sources\Exceptions\SourceRequestException;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Fallback input for a site that cannot serve Social Feed v1: read whatever it already publishes.
 *
 * Handles RSS 2.0, Atom and JSON Feed. Everything becomes `kind = article`, because that is all a
 * generic feed says about its entries — there is no price, no tier, no expiry to be had.
 */
final class RssFeedSource implements ContentSource
{
    private const MAX_ITEMS = 100;

    public function fetch(Source $source, ?CarbonImmutable $since): Generator
    {
        foreach ($this->entries($source) as $entry) {
            // RSS has no "since" parameter, so the filter happens here rather than at the source.
            if ($since !== null && $entry->updatedAt->lessThan($since)) {
                continue;
            }

            yield $entry;
        }
    }

    public function test(Source $source): SourceTestResult
    {
        $entries = $this->entries($source);
        $warnings = [];

        if ($entries === []) {
            $warnings[] = 'Feed nema nijedan zapis.';
        }

        foreach ($entries as $entry) {
            if ($entry->images === []) {
                $warnings[] = "Zapis „{$entry->title}\" nema sliku; Instagram objava će koristiti generički predložak.";
                break;
            }
        }

        return new SourceTestResult(
            brand: $source->brand?->slug,
            itemCount: count($entries),
            samples: array_slice($entries, 0, 3),
            warnings: array_values(array_unique($warnings)),
            nextCursor: null,
        );
    }

    /**
     * @return list<ContentItemData>
     */
    private function entries(Source $source): array
    {
        $url = (string) $source->base_url;

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Accept' => 'application/rss+xml, application/atom+xml, application/feed+json, application/json, text/xml;q=0.9'])
                ->timeout((int) config('hub.sync.http_timeout', 20))
                ->withUserAgent('social-hub/1.0 (+RSS)')
                ->get($url);
        } catch (ConnectionException $e) {
            throw new SourceRequestException("Feed {$url} nije dostupan: {$e->getMessage()}", null, $url);
        }

        if (! $response->successful()) {
            throw new SourceRequestException("{$url} je odgovorio HTTP {$response->status()}.", $response->status(), $url);
        }

        $body = mb_trim($response->body());

        if ($body === '') {
            throw new FeedValidationException(['Feed je prazan.'], $url);
        }

        return str_starts_with($body, '{')
            ? $this->fromJsonFeed($body, $url)
            : $this->fromXml($body, $url);
    }

    /**
     * @return list<ContentItemData>
     */
    private function fromJsonFeed(string $body, string $url): array
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new FeedValidationException(['Tijelo nije valjan JSON.'], $url);
        }

        if (! is_array($payload) || ! is_array($payload['items'] ?? null)) {
            throw new FeedValidationException(['JSON Feed nema polje "items".'], $url);
        }

        $items = [];

        foreach (array_slice($payload['items'], 0, self::MAX_ITEMS) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $link = (string) ($entry['url'] ?? $entry['external_url'] ?? '');

            if ($link === '') {
                continue;
            }

            $items[] = $this->item(
                id: (string) ($entry['id'] ?? $link),
                title: (string) ($entry['title'] ?? $link),
                summary: (string) ($entry['summary'] ?? $entry['content_text'] ?? $entry['content_html'] ?? ''),
                link: $link,
                image: isset($entry['image']) ? (string) $entry['image'] : null,
                author: is_array($entry['author'] ?? null) ? (string) ($entry['author']['name'] ?? '') : null,
                published: $entry['date_published'] ?? null,
                updated: $entry['date_modified'] ?? $entry['date_published'] ?? null,
                tags: array_map('strval', $entry['tags'] ?? []),
                raw: $entry,
            );
        }

        return $items;
    }

    /**
     * @return list<ContentItemData>
     */
    private function fromXml(string $body, string $url): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = new SimpleXMLElement($body, LIBXML_NOCDATA | LIBXML_NONET);
        } catch (Throwable $e) {
            throw new FeedValidationException(['Tijelo nije valjan XML: '.$e->getMessage()], $url);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // Any well-formed page parses as XML — an HTML error page included — so the root element,
        // not the presence of entries, decides whether this is a feed at all. An empty feed is
        // valid and must not be reported as broken.
        $root = mb_strtolower($xml->getName());

        if (! in_array($root, ['rss', 'feed', 'rdf'], true)) {
            throw new FeedValidationException(["Korijenski element je <{$root}>; očekivan je RSS (<rss>) ili Atom (<feed>)."], $url);
        }

        $entries = $root === 'feed' ? $xml->entry : $xml->channel->item;

        $items = [];

        foreach ($entries as $entry) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }

            $link = $this->link($entry);

            if ($link === '') {
                continue;
            }

            $media = $entry->children('http://search.yahoo.com/mrss/');
            $image = (string) ($entry->enclosure['url'] ?? $media->content['url'] ?? $media->thumbnail['url'] ?? '');
            $content = (string) ($entry->description ?? $entry->summary ?? $entry->content ?? '');

            if ($image === '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches) === 1) {
                $image = $matches[1];
            }

            $tags = [];
            foreach ($entry->category as $category) {
                $value = mb_trim((string) ($category['term'] ?? $category));
                if ($value !== '') {
                    $tags[] = $value;
                }
            }

            $items[] = $this->item(
                id: mb_trim((string) ($entry->guid ?? $entry->id ?? $link)),
                title: mb_trim((string) ($entry->title ?? $link)),
                summary: $content,
                link: $link,
                image: $image !== '' ? $image : null,
                author: mb_trim((string) ($entry->author->name ?? $entry->children('http://purl.org/dc/elements/1.1/')->creator ?? '')) ?: null,
                published: (string) ($entry->pubDate ?? $entry->published ?? '') ?: null,
                updated: (string) ($entry->updated ?? $entry->pubDate ?? $entry->published ?? '') ?: null,
                tags: $tags,
                raw: null,
            );
        }

        return $items;
    }

    private function link(SimpleXMLElement $entry): string
    {
        $link = mb_trim((string) $entry->link);

        if ($link !== '') {
            return $link;
        }

        // Atom carries the address in an attribute, and often several alternatives.
        foreach ($entry->link as $candidate) {
            $rel = (string) ($candidate['rel'] ?? 'alternate');
            $href = mb_trim((string) ($candidate['href'] ?? ''));

            if ($href !== '' && $rel === 'alternate') {
                return $href;
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>|null  $raw
     */
    private function item(
        string $id,
        string $title,
        string $summary,
        string $link,
        ?string $image,
        ?string $author,
        mixed $published,
        mixed $updated,
        array $tags,
        ?array $raw,
    ): ContentItemData {
        $publishedAt = $this->date($published);
        $updatedAt = $this->date($updated) ?? $publishedAt ?? CarbonImmutable::now();

        return new ContentItemData(
            externalId: 'rss:'.hash('sha256', $id !== '' ? $id : $link),
            kind: ContentKind::Article,
            title: mb_substr($title !== '' ? $title : $link, 0, 300),
            subtitle: $author,
            bodyText: $this->plainText($summary),
            facts: [],
            badges: [],
            price: null,
            cta: ['label' => 'Pročitaj', 'url' => $link],
            url: $link,
            images: $image !== null ? [['url' => $image, 'role' => 'primary', 'alt' => null]] : [],
            priority: null,
            tags: array_values(array_slice(array_unique($tags), 0, 20)),
            publishedAt: $publishedAt,
            expiresAt: null,
            updatedAt: $updatedAt,
            raw: $raw,
        );
    }

    private function plainText(string $html): ?string
    {
        $text = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "• $1\n", $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[^\S\r\n]+/u', ' ', $text) ?? $text) ?? $text);

        return $text === '' ? null : $text;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || mb_trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
