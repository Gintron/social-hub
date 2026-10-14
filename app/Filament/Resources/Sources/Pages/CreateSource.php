<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\SourceResource;
use App\Filament\Support\ReadableFormWidth;
use Filament\Resources\Pages\CreateRecord;

class CreateSource extends CreateRecord
{
    use ReadableFormWidth;

    protected static string $resource = SourceResource::class;

    public function getTitle(): string
    {
        return 'Novi izvor';
    }
}
