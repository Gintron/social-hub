<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts\Tables;

use App\Enums\AccountStatus;
use App\Models\SocialAccount;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Meta\GraphClient;
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
                    ->visible(fn (SocialAccount $record): bool => ! $record->platform->isManual())
                    ->action(function (SocialAccount $record): void {
                        try {
                            $me = app(GraphClient::class)->get($record->external_id, ['fields' => 'id,name,username'], (string) $record->access_token, 'verify');
                            $record->forceFill(['status' => AccountStatus::Active, 'last_verified_at' => now()])->save();
                            Notification::make()->title('Token radi')->body('Graph API vraća: '.($me['name'] ?? $me['username'] ?? $me['id'] ?? '?'))->success()->send();
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
}
