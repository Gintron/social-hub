<?php

declare(strict_types=1);

namespace App\Http\Controllers\TikTok;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Publishing\TikTok\TikTokClient;
use App\Publishing\TikTok\TikTokOAuth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects a brand's TikTok account.
 *
 * Same shape as the Meta flow, but the tokens are short-lived: what gets stored is an access token
 * good for a day plus the refresh token that keeps replacing it (see hub:refresh-tiktok-tokens).
 */
final class TikTokOAuthController extends Controller
{
    private const STATE_KEY = 'tiktok_oauth';

    public function __construct(private readonly TikTokOAuth $oauth) {}

    public function connect(Request $request, Brand $brand): RedirectResponse
    {
        $state = Str::random(40);

        $request->session()->put(self::STATE_KEY, ['state' => $state, 'brand_id' => $brand->id]);

        return redirect()->away($this->oauth->authorizeUrl($state));
    }

    public function callback(Request $request, TikTokClient $client): RedirectResponse
    {
        $expected = (array) $request->session()->pull(self::STATE_KEY, []);
        $back = redirect()->to(SocialAccountResource::getUrl('index'));

        if ($request->filled('error')) {
            return $this->fail($back, 'Spajanje je prekinuto', (string) $request->query('error_description', (string) $request->query('error')));
        }

        if (blank($expected['state'] ?? null) || ! hash_equals((string) $expected['state'], (string) $request->query('state', ''))) {
            return $this->fail($back, 'Spajanje nije uspjelo', 'State se ne podudara — pokušaj ponovno iz istog preglednika.');
        }

        $brand = Brand::query()->find($expected['brand_id'] ?? 0);

        if ($brand === null || ! $request->filled('code')) {
            return $this->fail($back, 'Spajanje nije uspjelo', 'Nedostaje brend ili kod koji TikTok vraća.');
        }

        try {
            $token = $this->oauth->exchangeCode((string) $request->query('code'));
            // The display name is not in the token response; the creator endpoint has it.
            $creator = $client->post('post/publish/creator_info/query/', [], $token['access_token'], 'tiktok.connect');
        } catch (Throwable $e) {
            return $this->fail($back, 'Spajanje nije uspjelo', $e->getMessage());
        }

        $username = (string) ($creator['creator_username'] ?? $token['open_id']);

        $account = SocialAccount::query()->updateOrCreate(
            ['platform' => Platform::TikTok->value, 'external_id' => $token['open_id']],
            [
                'brand_id' => $brand->id,
                'name' => '@'.$username,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'token_expires_at' => $token['expires_at'],
                'refresh_token_expires_at' => $token['refresh_expires_at'],
                'status' => AccountStatus::Active,
                'last_verified_at' => now(),
                'meta' => [
                    'open_id' => $token['open_id'],
                    'username' => $username,
                    'scope' => $token['scope'],
                    'privacy_level_options' => $creator['privacy_level_options'] ?? [],
                    'max_video_post_duration_sec' => $creator['max_video_post_duration_sec'] ?? null,
                ],
            ],
        );

        $options = implode(', ', (array) ($creator['privacy_level_options'] ?? []));

        Notification::make()
            ->title("TikTok račun {$account->name} povezan")
            ->body($options !== ''
                ? "Dopuštene razine privatnosti: {$options}."
                : 'TikTok nije vratio razine privatnosti; dok aplikacija ne prođe audit, objave su privatne.')
            ->success()
            ->send();

        return $back;
    }

    private function fail(RedirectResponse $response, string $title, string $body): RedirectResponse
    {
        Notification::make()->title($title)->body($body)->danger()->persistent()->send();

        return $response;
    }
}
