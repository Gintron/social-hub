<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;

/**
 * Given a user (or system user) token, lists the Pages it manages and stores a Page account plus the
 * linked Instagram Business account for each. IG publishing through Facebook Login uses the Page token.
 */
final class MetaAssetDiscovery
{
    public function __construct(private readonly GraphClient $graph) {}

    /**
     * @return list<SocialAccount>
     *
     * @throws \App\Publishing\Exceptions\PublishException
     */
    public function discover(Brand $brand, string $userToken): array
    {
        // Stored on every account so the deauthorize callback (which only knows the app-scoped user
        // id) can find the accounts that just lost their token.
        $connectedUserId = (string) ($this->graph->get('me', ['fields' => 'id'], $userToken, 'discover.me')['id'] ?? '');

        $response = $this->graph->get('me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name}',
            'limit' => 100,
        ], $userToken, 'discover');

        $accounts = [];

        foreach ($response['data'] ?? [] as $page) {
            $pageToken = (string) ($page['access_token'] ?? '');

            if ($pageToken === '' || blank($page['id'] ?? null)) {
                continue;
            }

            $accounts[] = SocialAccount::query()->updateOrCreate(
                ['platform' => Platform::FacebookPage->value, 'external_id' => (string) $page['id']],
                [
                    'brand_id' => $brand->id,
                    'name' => (string) ($page['name'] ?? $page['id']),
                    'access_token' => $pageToken,
                    'token_expires_at' => null,
                    'status' => AccountStatus::Active,
                    'last_verified_at' => now(),
                    'meta' => ['page_id' => (string) $page['id'], 'connected_user_id' => $connectedUserId],
                ],
            );

            $ig = $page['instagram_business_account'] ?? null;

            if (is_array($ig) && filled($ig['id'] ?? null)) {
                $accounts[] = SocialAccount::query()->updateOrCreate(
                    ['platform' => Platform::InstagramBusiness->value, 'external_id' => (string) $ig['id']],
                    [
                        'brand_id' => $brand->id,
                        'name' => '@'.($ig['username'] ?? $ig['id']),
                        'access_token' => $pageToken,
                        'token_expires_at' => null,
                        'status' => AccountStatus::Active,
                        'last_verified_at' => now(),
                        'meta' => ['page_id' => (string) $page['id'], 'connected_user_id' => $connectedUserId, 'username' => $ig['username'] ?? null, 'ig_name' => $ig['name'] ?? null],
                    ],
                );
            }
        }

        return $accounts;
    }
}
