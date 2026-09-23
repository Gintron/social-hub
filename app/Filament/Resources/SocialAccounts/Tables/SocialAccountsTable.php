<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts\Tables;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Meta\GraphClient;
use App\Publishing\TikTok\Business\TikTokBusinessClient;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SocialAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand.name')->label('Brend')->sortable(),
                TextColumn::make('platform')->label('Platforma')->badge(),
                TextColumn::make('name')->label('Račun')->searchable()->description(fn (SocialAccount $record): string => $record->external_id),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('last_verified_at')->label('Provjereno')->since()->placeholder('nikad'),
                TextColumn::make('token_expires_at')->label('Token istječe')->dateTime('d.m.Y', 'Europe/Zagreb')->placeholder('ne istječe'),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label('Provjeri')
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    // A developer-track TikTok token has nothing to be asked here; that track is on its way out.
                    ->visible(fn (SocialAccount $record): bool => ! $record->platform->isManual()
                        && ($record->platform !== Platform::TikTok || config('tiktok.driver') === 'business'))
                    ->action(function (SocialAccount $record): void {
                        try {
                            $answer = $record->platform === Platform::TikTok
                                ? self::tiktok($record)
                                : self::meta($record);
                            $record->forceFill(['status' => AccountStatus::Active, 'last_verified_at' => now()])->save();
                            Notification::make()->title('Token radi')->body($answer)->success()->send();
                        } catch (PublishException $e) {
                            if ($e->errorCode === 'token_invalid') {
                                $record->forceFill(['status' => AccountStatus::NeedsReconnect])->save();
                            }
                            Notification::make()->title('Provjera nije prošla')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                EditAction::make(),
            ]);
    }

    private static function meta(SocialAccount $record): string
    {
        $me = app(GraphClient::class)->get($record->external_id, ['fields' => 'id,name,username'], (string) $record->access_token, 'verify');

        return 'Graph API vraća: '.($me['name'] ?? $me['username'] ?? $me['id'] ?? '?');
    }

    private static function tiktok(SocialAccount $record): string
    {
        $me = app(TikTokBusinessClient::class)->get('business/get/', [
            'business_id' => (string) ($record->meta['open_id'] ?? $record->external_id),
            'fields' => json_encode(['username', 'display_name']),
        ], (string) $record->access_token, 'verify');

        return 'TikTok vraća: @'.($me['username'] ?? $me['display_name'] ?? '?');
    }
}
