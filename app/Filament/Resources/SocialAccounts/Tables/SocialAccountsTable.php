<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts\Tables;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\SocialAccount;
use App\Publishing\Exceptions\PublishException;
use App\Publishing\Meta\GraphClient;
use App\Publishing\TikTok\Business\TikTokBusinessClient;
use App\Publishing\YouTube\YouTubeClient;
use App\Publishing\YouTube\YouTubeTokens;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SocialAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('brand'))
            ->emptyStateIcon(Heroicon::OutlinedUserGroup)
            ->emptyStateHeading('Još nema povezanih računa')
            ->emptyStateDescription('Poveži Facebook stranicu, Instagram, TikTok ili YouTube brenda gumbima iznad tablice. Facebook grupu dodaj ručno (izbornik „Ručno“).')
            ->columns([
                TextColumn::make('platform')->label('Platforma')->badge()->formatStateUsing(fn (Platform $state): string => $state->shortLabel()),
                TextColumn::make('name')->label('Račun')->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('external_id', 'like', "%{$search}%")
                            ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$search}%")),
                    ))
                    // Active is the expected state, so only an exception is said under the name; the status column is for wider screens.
                    ->description(fn (SocialAccount $record): string => collect([
                        $record->brand?->name,
                        $record->status === AccountStatus::Active ? null : $record->status->label(),
                    ])->filter()->implode(' · ')),
                TextColumn::make('external_id')->label('ID / adresa')->limit(28)->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge()->visibleFrom('md'),
                TextColumn::make('last_verified_at')->label('Provjereno')->since()->placeholder('nikad')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('token_expires_at')->label('Token istječe')->dateTime('d.m.Y', 'Europe/Zagreb')->placeholder('ne istječe')->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label('Brend')->relationship('brand', 'name'),
                SelectFilter::make('platform')->label('Platforma')->options(collect(Platform::cases())->mapWithKeys(fn (Platform $platform): array => [$platform->value => $platform->shortLabel()])->all()),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label('Provjeri')
                    ->icon(Heroicon::OutlinedSignal)
                    ->iconButton()
                    ->tooltip('Provjeri token')
                    ->color('gray')
                    // A developer-track TikTok token has nothing to be asked here; that track is on its way out.
                    ->visible(fn (SocialAccount $record): bool => ! $record->platform->isManual()
                        && ($record->platform !== Platform::TikTok || config('tiktok.driver') === 'business'))
                    ->action(function (SocialAccount $record): void {
                        try {
                            $answer = match ($record->platform) {
                                Platform::TikTok => self::tiktok($record),
                                Platform::YouTube => self::youtube($record),
                                default => self::meta($record),
                            };
                            $record->forceFill(['status' => AccountStatus::Active, 'last_verified_at' => now()])->save();
                            Notification::make()->title('Token radi')->body($answer)->success()->send();
                        } catch (PublishException $e) {
                            if ($e->errorCode === 'token_invalid') {
                                $record->forceFill(['status' => AccountStatus::NeedsReconnect])->save();
                            }
                            Notification::make()->title('Provjera nije prošla')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                EditAction::make()->iconButton()->tooltip('Uredi račun'),
            ]);
    }

    private static function meta(SocialAccount $record): string
    {
        $me = app(GraphClient::class)->get($record->external_id, ['fields' => 'id,name,username'], (string) $record->access_token, 'verify');

        return 'Graph API vraća: '.($me['name'] ?? $me['username'] ?? $me['id'] ?? '?');
    }

    private static function youtube(SocialAccount $record): string
    {
        $token = app(YouTubeTokens::class)->accessTokenFor($record);
        $data = app(YouTubeClient::class)->get('channels', ['part' => 'snippet', 'mine' => 'true'], $token, 'verify');
        $channel = $data['items'][0] ?? null;

        return 'YouTube vraća: '.(is_array($channel) ? (string) ($channel['snippet']['title'] ?? $channel['id'] ?? '?') : 'nema kanala');
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
