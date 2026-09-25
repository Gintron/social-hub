<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Models\ContentItem;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is the item's own landing page still there, right before we tell people to go look at it?
 *
 * `expires_at` is a snapshot from the last successful sync — accurate the moment it was written,
 * but a source that silently stops returning an item (archived, pulled, replaced) never gets a
 * chance to correct it. A draft can sit in `pending_approval` for days, so by the time a human
 * approves it the cached expiry may already be stale. This checks the one thing that can't lie:
 * whether the URL we are about to publish actually resolves right now.
 *
 * A page that is gone (404/410) is remembered on the item (`link_dead_at`), so picks stop choosing
 * it; the next sync that sees the item again clears it.
 */
final class LinkPreflight
{
    /**
     * Hosts that did not answer during this instance's life: one pick asks each host once, instead
     * of waiting out the timeout for every candidate while the site is down.
     *
     * @var array<string, true>
     */
    private array $unreachable = [];

    /**
     * @return bool true when the item should be skipped, not published
     */
    public function isDead(ContentItem $item): bool
    {
        if (blank($item->url)) {
            return false;
        }

        // Can't confirm it's alive — treat like any other unreachable page. A retry costs one
        // re-approve; a broken link in a public post costs more.
        $status = $this->status($item);

        return $status === null || $status >= 400;
    }

    /**
     * For choosing what to post (a roundup, an automatic post): false for an item already known to
     * be dead, one whose page does not answer, or one on a host that already failed to answer.
     * Only a page that is gone is remembered; a timeout or a server error skips the item this once.
     */
    public function isAlive(ContentItem $item): bool
    {
        if ($item->link_dead_at !== null) {
            return false;
        }

        if (blank($item->url)) {
            return true;
        }

        $host = (string) parse_url((string) $item->url, PHP_URL_HOST);

        if (isset($this->unreachable[$host])) {
            return false;
        }

        $status = $this->status($item);

        if ($status === null) {
            $this->unreachable[$host] = true;

            return false;
        }

        return $status < 400;
    }

    /**
     * HTTP status of the item's page right now, or null when the host did not answer.
     */
    private function status(ContentItem $item): ?int
    {
        $timeout = (int) config('hub.limits.link_preflight_timeout', 8);

        try {
            // HEAD first — the page itself doesn't matter, only whether it answers. A server that
            // rejects HEAD (405/501) gets one GET before we trust the result.
            $status = Http::timeout($timeout)->head((string) $item->url)->status();

            if (in_array($status, [405, 501], true)) {
                $status = Http::timeout($timeout)->get((string) $item->url)->status();
            }
        } catch (Throwable) {
            return null;
        }

        if (in_array($status, [404, 410], true) && $item->exists && $item->link_dead_at === null) {
            $item->forceFill(['link_dead_at' => now()])->saveQuietly();
        }

        return $status;
    }
}
