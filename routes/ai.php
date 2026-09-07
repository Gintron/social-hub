<?php

declare(strict_types=1);

use App\Mcp\Servers\SocialHubServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * Local (stdio) transport for a developer's own machine: `php artisan mcp:start social-hub`.
 * Whoever can run artisan is already inside, so this transport is not additionally gated.
 */
Mcp::local('social-hub', SocialHubServer::class);

/*
 * Remote transport for agents and scheduled routines. The token decides what may be done:
 * `mcp` reads, `mcp:draft` writes drafts, `mcp:approve` approves, `mcp:publish` publishes.
 */
Mcp::web('/mcp', SocialHubServer::class)
    ->middleware(['auth:sanctum', 'abilities:mcp', 'throttle:120,1']);
