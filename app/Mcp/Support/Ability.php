<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Request;

/**
 * What a token is allowed to do.
 *
 * Agent tokens are scoped so a drafting agent can propose posts without being able to publish them:
 * `mcp` reads, `mcp:draft` writes drafts, `mcp:approve` approves and schedules, `mcp:publish` sends
 * a post out. A local (stdio) session has no token — whoever runs `artisan mcp:start` is already on
 * the machine, so there is nothing left to gate.
 */
final class Ability
{
    /**
     * @throws AuthorizationException
     */
    public static function require(Request $request, string $ability): void
    {
        $user = $request->user();

        if ($user === null) {
            return;
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if ($token === null) {
            return;
        }

        if (! $user->tokenCan($ability)) {
            throw new AuthorizationException("Ovaj token nema ovlast „{$ability}\".");
        }
    }

    public static function userId(Request $request): ?int
    {
        $user = $request->user();

        return $user?->getAuthIdentifier() === null ? null : (int) $user->getAuthIdentifier();
    }
}
