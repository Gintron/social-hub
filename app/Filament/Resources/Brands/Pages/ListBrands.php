<?php

declare(strict_types=1);

namespace App\Filament\Resources\Brands\Pages;

use App\Filament\Resources\Brands\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListBrands extends ListRecords
{
    protected static string $resource = BrandResource::class;

    protected static ?string $title = 'Brendovi';

    public function getSubheading(): string|Htmlable|null
    {
        return 'Svaki brend ima svoje boje, tekstove, račune i termine objave.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
