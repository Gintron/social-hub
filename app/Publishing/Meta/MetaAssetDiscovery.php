<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

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

        $pages = $response['data'] ?? [];

        // GraphClient only logs calls that belong to a post variant, and discovery has none — yet
        // this is the call that fails first when a Page is owned by a business portfolio or the
        // consent dialog granted nothing. Without this line the failure leaves no trace at all.
        Log::info('meta.discover', [
            'brand' => $brand->slug,
            'connected_user_id' => $connectedUserId,
            'page_count' => count($pages),
            'pages' => array_map(
                fn (array $page): array => [
                    'id' => $page['id'] ?? null,
                    'name' => $page['name'] ?? null,
                    'has_token' => filled($page['access_token'] ?? null),
                    'instagram' => $page['instagram_business_account']['id'] ?? null,
                ],
                $pages,
            ),
        ]);

        $accounts = [];

        foreach ($pages as $page) {
            $pageToken = (string) ($page['access_token'] ?? '');

            if ($pageToken === '' || blank($page['id'] ?? null)) {
                continue;
            }

            $accounts[] = $this->store(
                Platform::FacebookPage,
                (string) $page['id'],
                $brand,
                [
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
                $accounts[] = $this->store(
                    Platform::InstagramBusiness,
                    (string) $ig['id'],
                    $brand,
                    [
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

    /**
     * Discovery runs against whichever brand the operator picked, but a Page belongs to the brand it
     * was first filed under: a later reconnect (or a connect done for a sibling brand) must not drag
     * every account back onto that one brand and silently undo the split.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function store(Platform $platform, string $externalId, Brand $brand, array $attributes): SocialAccount
    {
        $account = SocialAccount::query()->firstOrNew([
            'platform' => $platform->value,
            'external_id' => $externalId,
        ]);

        if (! $account->exists) {
            $account->brand_id = $brand->id;
        }

        $account->fill($attributes)->save();

        return $account;
    }
}
