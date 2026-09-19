<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $fields = 'id,name,access_token,instagram_business_account{id,username,name}';

        // Stored on every account so the deauthorize callback (which only knows the app-scoped user
        // id) can find the accounts that just lost their token.
        $connectedUserId = (string) ($this->graph->get('me', ['fields' => 'id'], $userToken, 'discover.me')['id'] ?? '');

        $response = $this->graph->get('me/accounts', [
            'fields' => $fields,
            'limit' => 100,
        ], $userToken, 'discover');

        $returnedPages = collect($response['data'] ?? [])
            ->filter(fn (mixed $page): bool => is_array($page) && filled($page['id'] ?? null))
            ->keyBy(fn (array $page): string => (string) $page['id']);

        // Facebook Login for Business can grant a token granular access to a Page owned by a
        // business portfolio while omitting that Page from /me/accounts. On reconnect we already
        // know the Page that belongs to this brand, so ask Graph for it directly. A user token that
        // was not actually granted that asset still fails safely here.
        $knownPageIds = SocialAccount::query()
            ->where('brand_id', $brand->id)
            ->where('platform', Platform::FacebookPage->value)
            ->pluck('external_id')
            ->filter()
            ->map(fn (mixed $id): string => (string) $id)
            ->values();

        $directPages = collect();

        foreach ($knownPageIds as $pageId) {
            if ($returnedPages->has($pageId)) {
                continue;
            }

            try {
                $page = $this->graph->get($pageId, ['fields' => $fields], $userToken, 'discover.known_page');

                if ((string) ($page['id'] ?? '') === $pageId) {
                    $directPages->put($pageId, $page);
                }
            } catch (Throwable $e) {
                Log::warning('meta.discover_known_page_failed', [
                    'brand' => $brand->slug,
                    'page_id' => $pageId,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        $allPages = $returnedPages->union($directPages);

        // Once a brand has a Page assigned, reconnecting that brand must not overwrite a sibling
        // brand with an unrelated (and potentially unusable) Page token returned by Facebook.
        $pages = $knownPageIds->isNotEmpty()
            ? $allPages->only($knownPageIds)->values()->all()
            : $allPages->values()->all();

        // GraphClient only logs calls that belong to a post variant, and discovery has none — yet
        // this is the call that fails first when a Page is owned by a business portfolio or the
        // consent dialog granted nothing. Without this line the failure leaves no trace at all.
        Log::info('meta.discover', [
            'brand' => $brand->slug,
            'connected_user_id' => $connectedUserId,
            'page_count' => count($pages),
            'returned_page_count' => $returnedPages->count(),
            'direct_page_count' => $directPages->count(),
            'known_page_ids' => $knownPageIds->all(),
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
            $pageId = (string) ($page['id'] ?? '');

            if ($pageToken === '' || $pageId === '') {
                continue;
            }

            // Meta may return a Page from /me/accounts even when the freshly granted granular
            // permissions target a different asset. Validate the derived token before it can
            // overwrite a working token already stored by the hub.
            try {
                $verifiedPageId = (string) ($this->graph->get($pageId, ['fields' => 'id'], $pageToken, 'discover.page_token')['id'] ?? '');
            } catch (Throwable $e) {
                Log::warning('meta.discover_page_token_failed', [
                    'brand' => $brand->slug,
                    'page_id' => $pageId,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);

                continue;
            }

            if ($verifiedPageId !== $pageId) {
                continue;
            }

            $accounts[] = $this->store(
                Platform::FacebookPage,
                $pageId,
                $brand,
                [
                    'name' => (string) ($page['name'] ?? $pageId),
                    'access_token' => $pageToken,
                    'token_expires_at' => null,
                    'status' => AccountStatus::Active,
                    'last_verified_at' => now(),
                    'meta' => ['page_id' => $pageId, 'connected_user_id' => $connectedUserId],
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
                        'meta' => ['page_id' => $pageId, 'connected_user_id' => $connectedUserId, 'username' => $ig['username'] ?? null, 'ig_name' => $ig['name'] ?? null],
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
