<?php

declare(strict_types=1);

namespace App\Http\Controllers\YouTube;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Publishing\YouTube\YouTubeClient;
use App\Publishing\YouTube\YouTubeOAuth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Connects a brand's YouTube channel through Google's OAuth.
 *
 * Google's consent screen lets the person pick the channel, so one grant covers one channel and the
 * callback connects whichever channel the grant names. The state round-trip, and the refusal to move a
 * channel that already belongs to another brand, work as they do for TikTok.
 */
final class YouTubeOAuthController extends Controller
{
    private const STATE_KEY = 'youtube_oauth';

    public function __construct(private readonly YouTubeOAuth $oauth) {}

    public function connect(Request $request, Brand $brand): RedirectResponse
    {
        $state = Str::random(40);

        $request->session()->put(self::STATE_KEY, ['state' => $state, 'brand_id' => $brand->id]);

        return redirect()->away($this->oauth->authorizeUrl($state));
    }

    public function callback(Request $request, YouTubeClient $client): RedirectResponse
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
        $code = (string) $request->query('code', '');

        if ($brand === null || $code === '') {
            return $this->fail($back, 'Spajanje nije uspjelo', 'Nedostaje brend ili kod koji Google vraća.');
        }

        try {
            $token = $this->oauth->exchangeCode($code);
            $channel = $this->channel($client, $token['access_token']);
        } catch (Throwable $e) {
            return $this->fail($back, 'Spajanje nije uspjelo', $e->getMessage());
        }

        $channelId = (string) $channel['id'];
        $title = (string) ($channel['snippet']['title'] ?? $channelId);

        // The same channel reached through another brand's Google account must not change hands silently.
        $existing = SocialAccount::query()
            ->with('brand')
            ->where('platform', Platform::YouTube->value)
            ->where('external_id', $channelId)
            ->first();

        if ($existing !== null && $existing->brand_id !== $brand->id) {
            return $this->fail(
                $back,
                "{$title} je već povezan s brendom {$existing->brand?->name}",
                "Ovaj kanal već je u hubu za drugi brend. Za {$brand->name} prijavi se Google računom koji upravlja kanalom tog brenda.",
            );
        }

        // A renewal that carries no refresh token keeps the one already stored; a new connection needs one.
        $refresh = $token['refresh_token'] ?? $existing?->refresh_token;

        if (blank($refresh)) {
            return $this->fail($back, 'Spajanje nije uspjelo', 'Google nije vratio refresh token. Pokušaj ponovno i u dijalogu pristani na pristup.');
        }

        $account = SocialAccount::query()->updateOrCreate(
            ['platform' => Platform::YouTube->value, 'external_id' => $channelId],
            [
                'brand_id' => $brand->id,
                'name' => $title,
                'access_token' => $token['access_token'],
                'refresh_token' => $refresh,
                'token_expires_at' => $token['expires_at'],
                'refresh_token_expires_at' => null,
                'status' => AccountStatus::Active,
                'last_verified_at' => now(),
                'meta' => [
                    'channel_id' => $channelId,
                    'handle' => $channel['snippet']['customUrl'] ?? null,
                    'scope' => $token['scope'],
                    'api' => 'youtube',
                ],
            ],
        );

        Notification::make()
            ->title("YouTube kanal {$account->name} povezan")
            ->body('Objave su javne tek kad Google odobri aplikaciju (YouTube audit); dotad ih Google ostavlja privatnima. Dozvole: '.($token['scope'] !== '' ? $token['scope'] : 'nije prijavljeno.'))
            ->success()
            ->send();

        return $back;
    }

    /**
     * The channel the grant covers. Google's consent screen lets the person choose it, so there is one.
     *
     * @return array<string, mixed>
     */
    private function channel(YouTubeClient $client, string $token): array
    {
        $data = $client->get('channels', ['part' => 'snippet', 'mine' => 'true'], $token, 'youtube.connect');
        $channel = $data['items'][0] ?? null;

        if (! is_array($channel) || blank($channel['id'] ?? null)) {
            throw new RuntimeException('Ovaj Google račun nema YouTube kanal, ili u dijalogu nije odabran kanal brenda.');
        }

        return $channel;
    }

    private function fail(RedirectResponse $response, string $title, string $body): RedirectResponse
    {
        Notification::make()->title($title)->body($body)->danger()->persistent()->send();

        return $response;
    }
}
