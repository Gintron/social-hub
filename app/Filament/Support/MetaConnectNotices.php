<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\SocialAccount;
use Filament\Notifications\Notification;

/**
 * What the operator is told after a Facebook login, in the OAuth callback and in the paste-a-token
 * action alike.
 */
final class MetaConnectNotices
{
    /**
     * One line per account, with the brand it belongs to: a single login can now refresh the Pages
     * of several brands.
     *
     * @param  list<SocialAccount>  $accounts
     */
    public static function lines(array $accounts): string
    {
        return implode("\n", array_map(
            fn (SocialAccount $account): string => '• '.$account->platform->label().': '.$account->name.self::brandOf($account),
            $accounts,
        ));
    }

    /**
     * Facebook keeps one grant per user and app, so a login that did not tick a brand's Page took
     * that brand's access away. Say so now, not at the next scheduled post.
     *
     * @param  list<SocialAccount>  $lost
     */
    public static function lostAccess(array $lost): void
    {
        if ($lost === []) {
            return;
        }

        Notification::make()
            ->title('Ovo povezivanje je oduzelo pristup drugim računima')
            ->body(
                "Facebook drži jedno odobrenje za cijelu aplikaciju i ovo ga je zamijenilo, pa ovi računi više ne rade:\n"
                .self::lines($lost)
                ."\n\nPoveži brend ponovno i u Facebook dijalogu označi SVE stranice i Instagram račune svih brendova odjednom."
            )
            ->danger()
            ->persistent()
            ->send();
    }

    private static function brandOf(SocialAccount $account): string
    {
        $brand = $account->brand?->name;

        return $brand !== null ? " ({$brand})" : '';
    }
}
