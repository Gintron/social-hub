<?php

declare(strict_types=1);

namespace App\Http\Controllers\Meta;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Notifications\AccountNeedsReconnect;
use App\Publishing\Meta\SignedRequest;
use App\Support\AdminNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * The two callbacks every Meta app must expose: what to do when a user removes the app, and how a
 * user can request deletion of their data.
 *
 * Both are called by Meta, not by a browser, and authenticate themselves with `signed_request`.
 */
final class MetaWebhookController extends Controller
{
    public function __construct(private readonly AdminNotifier $notifier) {}

    /**
     * The app was removed from a Facebook account: its Page tokens are dead.
     */
    public function deauthorize(Request $request): JsonResponse
    {
        $payload = $this->verify($request);

        if ($payload === null) {
            return response()->json(['error' => 'invalid signed_request'], 400);
        }

        $userId = (string) ($payload['user_id'] ?? '');
        $accounts = $this->accountsOf($userId);

        foreach ($accounts as $account) {
            $account->forceFill(['status' => AccountStatus::NeedsReconnect])->save();
            $this->notifier->notify(new AccountNeedsReconnect($account, 'Aplikacija je uklonjena s Facebook računa (deauthorize callback).'));
        }

        Log::info('hub.meta.deauthorize', ['user_id' => $userId, 'accounts' => $accounts->count()]);

        return response()->json(['status' => 'ok', 'accounts' => $accounts->count()]);
    }

    /**
     * Data deletion request. Meta expects a status URL plus a confirmation code it can show the user.
     */
    public function dataDeletion(Request $request): JsonResponse
    {
        $payload = $this->verify($request);

        if ($payload === null) {
            return response()->json(['error' => 'invalid signed_request'], 400);
        }

        $userId = (string) ($payload['user_id'] ?? '');
        $code = Str::upper(Str::random(12));

        $accounts = $this->accountsOf($userId);

        foreach ($accounts as $account) {
            // The only Meta-derived data the hub stores is the access token and the page/IG ids.
            $account->forceFill([
                'access_token' => null,
                'status' => AccountStatus::Disabled,
                'meta' => array_merge($account->meta ?? [], ['deleted_at' => now()->toIso8601ZuluString(), 'deletion_code' => $code]),
            ])->save();
        }

        Log::info('hub.meta.data_deletion', ['user_id' => $userId, 'accounts' => $accounts->count(), 'code' => $code]);

        return response()->json([
            'url' => route('meta.data-deletion.status', ['code' => $code]),
            'confirmation_code' => $code,
        ]);
    }

    /**
     * Human-readable status page the confirmation code points at.
     */
    public function dataDeletionStatus(string $code): View
    {
        $accounts = SocialAccount::query()->where('meta->deletion_code', $code)->count();

        return view('meta.data-deletion', ['code' => $code, 'accounts' => $accounts]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verify(Request $request): ?array
    {
        $signed = (string) $request->input('signed_request', '');

        if ($signed === '') {
            return null;
        }

        try {
            return SignedRequest::parse($signed);
        } catch (Throwable $e) {
            Log::warning('hub.meta.signed_request_rejected', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, SocialAccount>
     */
    private function accountsOf(string $userId): \Illuminate\Database\Eloquent\Collection
    {
        if ($userId === '') {
            return SocialAccount::query()->whereRaw('1 = 0')->get();
        }

        return SocialAccount::query()->with('brand')->where('meta->connected_user_id', $userId)->get();
    }
}
