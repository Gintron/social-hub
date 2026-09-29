<?php

declare(strict_types=1);

namespace App\Publishing\Meta;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Publishing\Exceptions\TokenInvalidException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Given a user (or system user) token, lists the Pages it manages and stores a Page account plus the
 * linked Instagram Business account for each. IG publishing through Facebook Login uses the Page token.
 *
 * Facebook keeps ONE grant per user and app, and every login replaces the set of Pages it covers. A Page
 * token is only as good as that grant: connect brand B while ticking only B's Page and brand A's stored
 * token dies with "(#190) … permission(s) must be granted before impersonating a user's page", weeks
 * before anything would have expired. So a login refreshes every known Page the grant still covers
 * (whatever brand it belongs to) and reports the ones it did not, instead of leaving them to fail at
 * publish time.
 */
final class MetaAssetDiscovery
{
    public function __construct(private readonly GraphClient $graph) {}

    /**
     * @throws \App\Publishing\Exceptions\PublishException
     */
    public function discover(Brand $brand, string $userToken): DiscoveryResult
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

        $known = SocialAccount::query()
            ->where('platform', Platform::FacebookPage->value)
            ->get(['id', 'brand_id', 'external_id', 'status'])
            // Plain collection: an empty Eloquent one stays Eloquent through map(), and its merge() wants models.
            ->toBase();

        $ownPageIds = $known
            ->filter(fn (SocialAccount $account): bool => (int) $account->brand_id === $brand->id)
            ->map(fn (SocialAccount $account): string => (string) $account->external_id)
            ->filter()
            ->values();

        // Pages of the other brands ride on the same grant. A disabled one stays that way: it was
        // switched off (or its data deleted) on purpose, and another brand's login must not undo it.
        $siblingPageIds = $known
            ->filter(fn (SocialAccount $account): bool => (int) $account->brand_id !== $brand->id && $account->status !== AccountStatus::Disabled)
            ->map(fn (SocialAccount $account): string => (string) $account->external_id)
            ->filter()
            ->values();

        $disabledElsewhere = $known
            ->filter(fn (SocialAccount $account): bool => (int) $account->brand_id !== $brand->id && $account->status === AccountStatus::Disabled)
            ->map(fn (SocialAccount $account): string => (string) $account->external_id)
            ->values();

        // Facebook Login for Business can grant a token granular access to a Page owned by a
        // business portfolio while omitting that Page from /me/accounts. On reconnect we already
        // know the Pages that exist, so ask Graph for them directly. A user token that was not
        // actually granted that asset still fails safely here.
        $directPages = collect();

        foreach ($ownPageIds->merge($siblingPageIds)->unique() as $pageId) {
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
                    'sibling' => ! $ownPageIds->containsStrict($pageId),
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        $allPages = $returnedPages->union($directPages);

        // Once a brand has a Page assigned, reconnecting that brand must not pull in unrelated Pages
        // Facebook happens to return: only this brand's Pages and the ones already known under
        // other brands are taken. A brand without a Page yet takes what the login offers.
        $pages = ($ownPageIds->isNotEmpty() ? $allPages->only($ownPageIds->merge($siblingPageIds)->all()) : $allPages)
            ->reject(fn (array $page): bool => $disabledElsewhere->containsStrict((string) ($page['id'] ?? '')))
            ->values()
            ->all();

        // GraphClient only logs calls that belong to a post variant, and discovery has none — yet
        // this is the call that fails first when a Page is owned by a business portfolio or the
        // consent dialog granted nothing. Without this line the failure leaves no trace at all.
        Log::info('meta.discover', [
            'brand' => $brand->slug,
            'connected_user_id' => $connectedUserId,
            'page_count' => count($pages),
            'returned_page_count' => $returnedPages->count(),
            'direct_page_count' => $directPages->count(),
            'known_page_ids' => $ownPageIds->all(),
            'sibling_page_ids' => $siblingPageIds->all(),
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

        return new DiscoveryResult($accounts, $this->lostAccess($accounts));
    }

    /**
     * Accounts this login did not touch but whose stored token it may have killed. Asked, not assumed:
     * a token that still answers is left alone, and an answer that is not a clear "token invalid"
     * (Graph down, a timeout) flags nothing.
     *
     * @param  list<SocialAccount>  $refreshed
     * @return list<SocialAccount>
     */
    private function lostAccess(array $refreshed): array
    {
        $untouched = SocialAccount::query()->with('brand')->active()
            ->whereIn('platform', [Platform::FacebookPage->value, Platform::InstagramBusiness->value])
            ->whereNotNull('access_token')
            ->whereNotIn('id', array_map(fn (SocialAccount $account): int => $account->id, $refreshed))
            ->get();

        $lost = [];

        foreach ($untouched as $account) {
            try {
                $this->graph->get($account->external_id, ['fields' => 'id'], (string) $account->access_token, 'discover.sibling_check');
            } catch (TokenInvalidException $e) {
                $account->forceFill(['status' => AccountStatus::NeedsReconnect])->save();
                $lost[] = $account;

                Log::warning('meta.lost_access', [
                    'account' => $account->id,
                    'platform' => $account->platform->value,
                    'external_id' => $account->external_id,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            } catch (Throwable $e) {
                Log::warning('meta.sibling_check_failed', [
                    'account' => $account->id,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        return $lost;
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
