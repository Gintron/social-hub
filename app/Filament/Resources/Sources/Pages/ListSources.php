<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\SourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListSources extends ListRecords
{
    protected static string $resource = SourceResource::class;

    protected static ?string $title = 'Izvori sadržaja';

    public function getSubheading(): string|Htmlable|null
    {
        return 'Stranice iz kojih hub čita sadržaj. Nova stranica mora ispuniti ugovor Social Feed v1.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
