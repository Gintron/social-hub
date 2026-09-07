<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentItems\Pages;

use App\Filament\Resources\ContentItems\ContentItemActions;
use App\Filament\Resources\ContentItems\ContentItemResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewContentItem extends ViewRecord
{
    protected static string $resource = ContentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ContentItemActions::createDraft(),
        ];
    }
}
