<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts\Pages;

use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Filament\Support\ReadableFormWidth;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSocialAccount extends EditRecord
{
    use ReadableFormWidth;

    protected static string $resource = SocialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
