<?php

declare(strict_types=1);

namespace Tests\Feature\Sources;

use App\Enums\AuthType;
use App\Enums\ContentKind;
use App\Enums\SourceType;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Source;
use App\Sources\Exceptions\FeedValidationException;
use App\Sources\RssFeedSource;
use App\Sources\SourceSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The fallback input: a site that cannot serve Social Feed v1 but already publishes a feed.
 */
final class RssFeedSourceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://blog.test/feed.xml';

    public function test_reads_an_rss_2_feed(): void
    {
        Http::fake([self::URL => Http::response($this->rss(), 200, ['Content-Type' => 'application/rss+xml'])]);

        $items = iterator_to_array(app(RssFeedSource::class)->fetch($this->source(), null), false);

        $this->assertCount(2, $items);

        $first = $items[0];
        $this->assertSame(ContentKind::Article, $first->kind);
        $this->assertSame('Kako napisati dobar oglas', $first->title);
        $this->assertSame('https://blog.test/dobar-oglas', $first->url);
        $this->assertStringContainsString('Naslov je prvo što kandidat vidi', (string) $first->bodyText);
        $this->assertStringNotContainsString('<', (string) $first->bodyText);
        $this->assertSame('https://blog.test/slike/oglas.jpg', $first->images[0]['url']);
        $this->assertSame('2026-09-01T09:00:00Z', $first->updatedAt->toIso8601ZuluString());
        $this->assertSame(['Savjeti', 'Poslodavci'], $first->tags);
        $this->assertStringStartsWith('rss:', $first->externalId);

        // No enclosure: the image is recovered from the body so the post is not left blank.
        $this->assertSame('https://blog.test/slike/place.png', $items[1]->images[0]['url']);
    }

    public function test_reads_an_atom_feed(): void
    {
        Http::fake([self::URL => Http::response($this->atom(), 200, ['Content-Type' => 'application/atom+xml'])]);

        $items = iterator_to_array(app(RssFeedSource::class)->fetch($this->source(), null), false);

        $this->assertCount(1, $items);
        $this->assertSame('Nova sezona', $items[0]->title);
        $this->assertSame('https://blog.test/nova-sezona', $items[0]->url);
        $this->assertSame('Ivana Ivić', $items[0]->subtitle);
    }

    public function test_reads_a_json_feed(): void
    {
        Http::fake([self::URL => Http::response([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Blog',
            'items' => [[
                'id' => 'post-1',
                'url' => 'https://blog.test/post-1',
                'title' => 'Prvi post',
                'content_text' => 'Tekst posta.',
                'image' => 'https://blog.test/slike/post-1.jpg',
                'date_published' => '2026-09-02T08:00:00Z',
                'tags' => ['blog'],
            ]],
        ])]);

        $items = iterator_to_array(app(RssFeedSource::class)->fetch($this->source(), null), false);

        $this->assertSame('Prvi post', $items[0]->title);
        $this->assertSame('Tekst posta.', $items[0]->bodyText);
        $this->assertSame('https://blog.test/slike/post-1.jpg', $items[0]->images[0]['url']);
        $this->assertSame(['blog'], $items[0]->tags);
    }

    public function test_since_is_applied_locally_because_rss_cannot_filter(): void
    {
        Http::fake([self::URL => Http::response($this->rss())]);

        $items = iterator_to_array(
            app(RssFeedSource::class)->fetch($this->source(), now()->parse('2026-09-02T00:00:00Z')->toImmutable()),
            false,
        );

        $this->assertSame(['Koliko plaćati sezonce'], array_map(fn ($item): string => $item->title, $items));
    }

    public function test_a_page_that_is_not_a_feed_is_rejected_with_a_readable_reason(): void
    {
        Http::fake([self::URL => Http::response('<html><body>404</body></html>')]);

        $this->expectException(FeedValidationException::class);
        $this->expectExceptionMessageMatches('/očekivan je RSS/');

        iterator_to_array(app(RssFeedSource::class)->fetch($this->source(), null), false);
    }

    public function test_an_empty_feed_is_empty_not_broken(): void
    {
        Http::fake([self::URL => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><title>Blog</title></channel></rss>')]);

        $this->assertSame([], iterator_to_array(app(RssFeedSource::class)->fetch($this->source(), null), false));
        $this->assertStringContainsString('nema nijedan zapis', implode("\n", app(RssFeedSource::class)->test($this->source())->warnings));
    }

    public function test_test_connection_warns_about_entries_without_images(): void
    {
        Http::fake([self::URL => Http::response($this->atom())]);

        $result = app(RssFeedSource::class)->test($this->source());

        $this->assertSame(1, $result->itemCount);
        $this->assertFalse($result->ok());
        $this->assertStringContainsString('nema sliku', implode("\n", $result->warnings));
    }

    public function test_a_synced_rss_item_looks_like_any_other_content_item(): void
    {
        Http::fake([self::URL => Http::response($this->rss())]);

        $source = $this->source();
        $result = app(SourceSyncer::class)->sync($source);

        $this->assertSame(2, $result->created);

        $item = ContentItem::query()->where('title', 'Kako napisati dobar oglas')->firstOrFail();
        $this->assertSame(ContentKind::Article, $item->kind);
        $this->assertSame($source->brand_id, $item->brand_id);
        $this->assertSame('https://blog.test/slike/oglas.jpg', $item->imageUrl('primary'));
        $this->assertNull($item->expires_at, 'članak ne istječe sam od sebe');

        // Same feed again. The entries are older than the overlap window, so the local `since`
        // filter drops them before the upsert — either way nothing may be duplicated.
        app(SourceSyncer::class)->sync($source->refresh());
        $this->assertSame(2, ContentItem::query()->count());
    }

    private function source(): Source
    {
        return Source::factory()->for(Brand::factory()->create())->create([
            'type' => SourceType::Rss,
            'auth_type' => AuthType::None,
            'base_url' => self::URL,
            'secret' => null,
        ]);
    }

    private function rss(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0">
              <channel>
                <title>Blog</title>
                <item>
                  <title>Kako napisati dobar oglas</title>
                  <link>https://blog.test/dobar-oglas</link>
                  <guid>https://blog.test/dobar-oglas</guid>
                  <description><![CDATA[<p>Naslov je prvo &scaron;to kandidat vidi.</p><ul><li>Budi konkretan</li></ul>]]></description>
                  <enclosure url="https://blog.test/slike/oglas.jpg" type="image/jpeg" length="12345"/>
                  <category>Savjeti</category>
                  <category>Poslodavci</category>
                  <pubDate>Tue, 01 Sep 2026 09:00:00 +0000</pubDate>
                </item>
                <item>
                  <title>Koliko plaćati sezonce</title>
                  <link>https://blog.test/place-sezonce</link>
                  <description><![CDATA[<p>Pregled satnica <img src="https://blog.test/slike/place.png"> po županijama.</p>]]></description>
                  <pubDate>Thu, 03 Sep 2026 07:30:00 +0000</pubDate>
                </item>
              </channel>
            </rss>
            XML;
    }

    private function atom(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Blog</title>
              <entry>
                <title>Nova sezona</title>
                <link rel="alternate" href="https://blog.test/nova-sezona"/>
                <id>tag:blog.test,2026:nova-sezona</id>
                <author><name>Ivana Ivić</name></author>
                <summary>Sezona počinje ranije nego lani.</summary>
                <updated>2026-09-04T10:15:00Z</updated>
              </entry>
            </feed>
            XML;
    }
}
