<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentItems\Pages;

use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Jobs\SyncSourceJob;
use App\Models\Source;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListContentItems extends ListRecords
{
    protected static string $resource = ContentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_all')
                ->label('Sinkroniziraj izvore')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    $sources = Source::query()->enabled()->pull()->get();
                    $sources->each(fn (Source $source) => SyncSourceJob::dispatch($source->id));

                    Notification::make()->title("Sinkronizacija pokrenuta za {$sources->count()} izvor(a)")->body('Novi kandidati stižu za koju minutu.')->success()->send();
                }),
        ];
    }
}
