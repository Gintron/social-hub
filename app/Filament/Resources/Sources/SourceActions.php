<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sources;

use App\Models\Source;
use App\Sources\ContentItemData;
use App\Sources\Exceptions\SourceException;
use App\Sources\SourceRegistry;
use App\Sources\SourceSyncer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Shared "Test connection" and "Sync now" actions for the sources table and edit page.
 */
final class SourceActions
{
    public static function test(): Action
    {
        return Action::make('test')
            ->label('Testiraj vezu')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->action(function (Source $record): void {
                try {
                    $result = app(SourceRegistry::class)->for($record)->test($record);
                } catch (SourceException $e) {
                    Notification::make()->title('Veza ne radi')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                $samples = implode("\n", array_map(
                    fn (ContentItemData $item): string => "• [{$item->kind->value}] {$item->title}",
                    $result->samples,
                ));

                $body = "Brend u feedu: {$result->brand}. Stavki na prvoj stranici: {$result->itemCount}.\n".$samples;

                if ($result->ok()) {
                    Notification::make()->title('Feed je valjan')->body($body)->success()->send();
                } else {
                    Notification::make()->title('Feed radi, ali ima upozorenja')
                        ->body($body."\n\n⚠ ".implode("\n⚠ ", $result->warnings))
                        ->warning()->persistent()->send();
                }
            });
    }

    public static function syncNow(): Action
    {
        return Action::make('sync')
            ->label('Sinkroniziraj')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (Source $record): void {
                try {
                    $result = app(SourceSyncer::class)->sync($record);
                } catch (Throwable $e) {
                    Notification::make()->title('Sinkronizacija nije uspjela')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title('Sinkronizirano')
                    ->body("{$result->created} novih, {$result->updated} izmijenjenih, {$result->unchanged} nepromijenjenih.")
                    ->success()->send();
            });
    }
}
