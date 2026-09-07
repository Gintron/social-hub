<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\SourceActions;
use App\Filament\Resources\Sources\SourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditSource extends EditRecord
{
    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SourceActions::test(),
            SourceActions::syncNow(),
            DeleteAction::make(),
        ];
    }
}
