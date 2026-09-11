<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Models\ContentItem;
use Illuminate\Http\Client\ConnectionException;
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
 */
final class LinkPreflight
{
    /**
     * @return bool true when the item should be skipped, not published
     */
    public function isDead(ContentItem $item): bool
    {
        $url = $item->url;

        if (blank($url)) {
            return false;
        }

        try {
            // HEAD first — the page itself doesn't matter, only whether it answers. A server that
            // rejects HEAD (405/501) gets one GET before we trust the result.
            $status = Http::timeout((int) config('hub.limits.link_preflight_timeout', 8))->head($url)->status();

            if (in_array($status, [405, 501], true)) {
                $status = Http::timeout((int) config('hub.limits.link_preflight_timeout', 8))->get($url)->status();
            }
        } catch (ConnectionException) {
            // Can't confirm it's alive — treat like any other unreachable page. A retry costs one
            // re-approve; a broken link in a public post costs more.
            return true;
        } catch (Throwable) {
            return true;
        }

        return $status >= 400;
    }
}
