<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts\Schemas;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SocialAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Račun')
                    ->description('Facebook Page i Instagram najlakše se dodaju akcijom "Otkrij Meta račune" na popisu. Facebook grupa je ručni kanal: upiši naziv i URL grupe.')
                    ->schema([
                        Select::make('brand_id')->label('Brend')->relationship('brand', 'name')->required()->searchable()->preload(),
                        Select::make('platform')->label('Platforma')->options(Platform::class)->required(),
                        TextInput::make('name')->label('Naziv')->required()->maxLength(191),
                        TextInput::make('external_id')->label('Vanjski ID / URL grupe')->required()->maxLength(191)
                            ->helperText('Page ID, Instagram user ID, ili URL Facebook grupe.'),
                        Select::make('status')->label('Status')->options(AccountStatus::class)->default(AccountStatus::Active)->required(),
                        DateTimePicker::make('token_expires_at')->label('Token istječe')->timezone('Europe/Zagreb')->seconds(false),
                        Textarea::make('access_token')->label('Access token')->rows(3)->columnSpanFull()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Sprema se šifrirano; ostavi prazno da zadržiš postojeći. Za grupe nije potreban.'),
                    ])->columns(2),

                Section::make('Meta podaci')
                    ->schema([
                        KeyValue::make('meta')->label('')->keyLabel('Ključ')->valueLabel('Vrijednost'),
                    ])->collapsed(),
            ]);
    }
}
