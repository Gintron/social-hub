<?php

declare(strict_types=1);

namespace App\Filament\Resources\SocialAccounts\Pages;

use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Filament\Support\ReadableFormWidth;
use Filament\Resources\Pages\CreateRecord;

class CreateSocialAccount extends CreateRecord
{
    use ReadableFormWidth;

    protected static string $resource = SocialAccountResource::class;

    public function getTitle(): string
    {
        return 'Novi račun';
    }
}
