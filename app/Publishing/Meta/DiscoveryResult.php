<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Models\SocialAccount;

/**
 * What one Facebook login did to the hub's accounts.
 *
 * Facebook keeps a single grant per user and app, so a login is never only about the brand it was
 * started for: it can refresh the Pages of other brands, and it can take access away from them.
 */
final readonly class DiscoveryResult
{
    /**
     * @param  list<SocialAccount>  $accounts  stored or refreshed by this login, of every brand
     * @param  list<SocialAccount>  $lostAccess  accounts whose stored token this login left dead
     */
    public function __construct(
        public array $accounts,
        public array $lostAccess = [],
    ) {}
}
