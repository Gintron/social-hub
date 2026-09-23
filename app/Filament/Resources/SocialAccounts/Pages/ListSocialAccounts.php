<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts\Pages;

use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Meta\MetaAssetDiscovery;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

final class ListSocialAccounts extends ListRecords
{
    protected static string $resource = SocialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Poveži preko Facebooka')
                ->icon(Heroicon::OutlinedLink)
                ->color('primary')
                ->modalDescription('Otvara Facebook dijalog za pristanak. Nakon potvrde hub sprema Page tokene (ne istječu) i Instagram Business račune povezane s tim stranicama.')
                ->modalSubmitActionLabel('Nastavi na Facebook')
                ->visible(fn (): bool => filled(config('meta.app_id')))
                ->schema([
                    Select::make('brand_id')->label('Brend')->options(Brand::query()->orderBy('name')->pluck('name', 'id'))->required(),
                ])
                ->action(function (array $data, Component $livewire): void {
                    $livewire->redirect(route('meta.connect', ['brand' => (int) $data['brand_id']]), navigate: false);
                }),

            Action::make('connect_tiktok')
                ->label('Poveži TikTok')
                ->icon(Heroicon::OutlinedVideoCamera)
                ->color('gray')
                ->modalDescription(fn (): string => self::tiktokBusiness()
                    ? 'Otvara TikTok dijalog za pristanak (API for Business). Prijavi se u TikTok račun brenda koji povezuješ.'
                    : 'Otvara TikTok dijalog za pristanak. Dok aplikacija ne prođe TikTok audit, objave su vidljive samo vlasniku računa (SELF_ONLY).')
                ->modalSubmitActionLabel('Nastavi na TikTok')
                ->visible(fn (): bool => self::tiktokBusiness()
                    ? filled(config('tiktok.business.app_id')) && filled(config('tiktok.business.authorize_url'))
                    : filled(config('tiktok.client_key')))
                ->schema([
                    Select::make('brand_id')->label('Brend')->options(Brand::query()->orderBy('name')->pluck('name', 'id'))->required(),
                ])
                ->action(function (array $data, Component $livewire): void {
                    $route = self::tiktokBusiness() ? 'tiktok.business.connect' : 'tiktok.connect';

                    $livewire->redirect(route($route, ['brand' => (int) $data['brand_id']]), navigate: false);
                }),

            Action::make('discover')
                ->label('Zalijepi token ručno')
                ->icon(Heroicon::OutlinedCloudArrowDown)
                ->color('gray')
                ->modalDescription('Zalijepi korisnički ili System User token s dozvolama pages_show_list, pages_manage_posts, pages_read_engagement, instagram_basic, instagram_content_publish. Hub sprema Page tokene (ne istječu) za svaki Page i povezani Instagram Business račun.')
                ->schema([
                    Select::make('brand_id')->label('Brend')->options(Brand::query()->orderBy('name')->pluck('name', 'id'))->required(),
                    Textarea::make('token')->label('Token')->rows(3)->required(),
                ])
                ->action(function (array $data): void {
                    $brand = Brand::query()->findOrFail((int) $data['brand_id']);

                    try {
                        $accounts = app(MetaAssetDiscovery::class)->discover($brand, mb_trim((string) $data['token']));
                    } catch (PublishException $e) {
                        Notification::make()->title('Graph API je odbio token')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $names = implode("\n", array_map(fn (SocialAccount $account): string => '• '.$account->platform->label().': '.$account->name, $accounts));

                    Notification::make()
                        ->title(count($accounts) > 0 ? count($accounts).' račun(a) spremljeno' : 'Token ne upravlja nijednim Pageom')
                        ->body($names !== '' ? $names : 'Provjeri da korisnik ima ulogu na Pageu i da su dozvole odobrene.')
                        ->status(count($accounts) > 0 ? 'success' : 'warning')
                        ->send();
                }),
            CreateAction::make()->label('Dodaj ručno'),
        ];
    }

    /**
     * Temporary, like the driver switch itself: goes when the developer track is removed.
     */
    private static function tiktokBusiness(): bool
    {
        return config('tiktok.driver') === 'business';
    }
}
