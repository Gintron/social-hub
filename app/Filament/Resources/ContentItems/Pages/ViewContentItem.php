<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentItems\Pages;

use App\Filament\Resources\ContentItems\ContentItemActions;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Models\ContentItem;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

final class ViewContentItem extends ViewRecord
{
    protected static string $resource = ContentItemResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->item()->title;
    }

    public function getBreadcrumb(): string
    {
        return 'Pregled';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $item = $this->item();

        return collect([
            $item->brand?->name,
            $item->kind->label(),
            $item->expires_at ? 'istječe '.$item->expires_at->timezone('Europe/Zagreb')->format('d.m.Y') : null,
        ])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            ContentItemActions::createDraft(),
        ];
    }

    private function item(): ContentItem
    {
        /** @var ContentItem $record */
        $record = $this->getRecord();

        return $record;
    }
}
