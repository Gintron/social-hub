<?php

declare(strict_types=1);

namespace Tests\Unit\Publishing;

use App\Models\ContentItem;
use App\Publishing\LinkPreflight;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LinkPreflightTest extends TestCase
{
    public function test_a_reachable_page_is_not_dead(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertFalse((new LinkPreflight)->isDead($this->item('https://uselisto.test/live')));
    }

    public function test_a_404_page_is_dead(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->assertTrue((new LinkPreflight)->isDead($this->item('https://uselisto.test/gone')));
    }

    public function test_a_server_that_rejects_head_gets_one_get_before_giving_up(): void
    {
        Http::fake([
            'uselisto.test/*' => Http::sequence()
                ->push('', 405)
                ->push('', 200),
        ]);

        $this->assertFalse((new LinkPreflight)->isDead($this->item('https://uselisto.test/head-not-allowed')));
        Http::assertSentCount(2);
    }

    public function test_a_connection_failure_counts_as_dead(): void
    {
        Http::fake(function (): void {
            throw new \Illuminate\Http\Client\ConnectionException('could not resolve host');
        });

        $this->assertTrue((new LinkPreflight)->isDead($this->item('https://uselisto.test/unreachable')));
    }

    public function test_a_pick_asks_a_host_that_did_not_answer_only_once(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls): void {
            $calls++;

            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        $links = new LinkPreflight;

        $this->assertFalse($links->isAlive($this->item('https://uselisto.test/a')));
        $this->assertFalse($links->isAlive($this->item('https://uselisto.test/b')));
        $this->assertSame(1, $calls);
    }

    public function test_an_item_known_to_be_dead_is_not_asked_again(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $item = $this->item('https://uselisto.test/gone');
        $item->link_dead_at = now()->toImmutable();

        $this->assertFalse((new LinkPreflight)->isAlive($item));
        Http::assertNothingSent();
    }

    public function test_an_item_without_a_url_is_never_dead(): void
    {
        Http::fake();

        $this->assertFalse((new LinkPreflight)->isDead($this->item(null)));
        Http::assertNothingSent();
    }

    private function item(?string $url): ContentItem
    {
        return ContentItem::factory()->make(['url' => $url]);
    }
}
