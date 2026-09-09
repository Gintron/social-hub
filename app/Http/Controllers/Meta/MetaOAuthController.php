<?php

declare(strict_types=1);

namespace App\Http\Controllers\Meta;

use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Publishing\Meta\MetaAssetDiscovery;
use App\Publishing\Meta\MetaOAuth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects a brand's Facebook Pages (and the Instagram accounts linked to them) by sending an admin
 * through the Facebook consent dialog and storing the Page tokens that come back.
 */
final class MetaOAuthController extends Controller
{
    private const STATE_KEY = 'meta_oauth';

    public function __construct(private readonly MetaOAuth $oauth) {}

    /**
     * Start the flow for one brand.
     */
    public function connect(Request $request, Brand $brand): RedirectResponse
    {
        $state = Str::random(40);

        // Session-bound state, the standard OAuth CSRF guard: the callback is only accepted if it
        // carries the value this browser was sent away with.
        $request->session()->put(self::STATE_KEY, [
            'state' => $state,
            'brand_id' => $brand->id,
        ]);

        return redirect()->away($this->oauth->dialogUrl($state));
    }

    /**
     * Handle the return from Facebook.
     */
    public function callback(Request $request): RedirectResponse
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

        if ($brand === null) {
            return $this->fail($back, 'Spajanje nije uspjelo', 'Brend više ne postoji.');
        }

        if (! $request->filled('code')) {
            return $this->fail($back, 'Spajanje nije uspjelo', 'Facebook nije vratio kod.');
        }

        try {
            $token = $this->oauth->exchangeCode((string) $request->query('code'));
            $accounts = app(MetaAssetDiscovery::class)->discover($brand, $token['token']);
        } catch (Throwable $e) {
            return $this->fail($back, 'Spajanje nije uspjelo', $e->getMessage());
        }

        if ($accounts === []) {
            return $this->fail(
                $back,
                'Nijedan Page nije povezan',
                'Token ne upravlja nijednim Pageom. Provjeri da imaš ulogu na Pageu i da su dozvole odobrene u '
                .'dijalogu. Ako je Page u vlasništvu poslovnog portfelja, u dijalogu treba odabrati taj '
                .'portfelj — bez Login konfiguracije (META_LOGIN_CONFIG_ID) takvi Pageovi se uopće ne nude. '
                .'Točan odgovor Facebooka zapisan je u log pod „meta.discover".',
            );
        }

        $names = collect($accounts)->map(fn (SocialAccount $account): string => '• '.$account->platform->label().': '.$account->name)->implode("\n");

        Notification::make()
            ->title(count($accounts).' račun(a) povezano za '.$brand->name)
            ->body($names)
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
