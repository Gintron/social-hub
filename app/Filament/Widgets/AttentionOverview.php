<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\PostDrafts\PostDraftResource;
use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Filament\Resources\Sources\SourceResource;
use App\Filament\Support\HubStatus;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Four numbers, each a door to the list that explains it. A number that asks for nothing is grey,
 * so the colour on the screen is the work to do.
 */
final class AttentionOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    /**
     * @return array<string, int>
     */
    protected function getColumns(): array
    {
        return ['default' => 2, 'lg' => 4];
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $status = app(HubStatus::class);

        $pending = $status->pendingApproval();
        $failed = $status->needFixing();
        $accounts = $status->accountsToReconnect();
        $sources = $status->sourcesWithProblems();

        return [
            Stat::make('Čeka tvoje odobrenje', $pending)
                ->description($pending > 0 ? 'Pregledaj i odobri' : 'Sve je pregledano')
                ->descriptionIcon($pending > 0 ? Heroicon::OutlinedArrowRight : Heroicon::OutlinedCheckCircle)
                ->color($pending > 0 ? 'warning' : 'gray')
                ->url(PostDraftResource::getUrl('index', ['tab' => 'pending'])),

            Stat::make('Zakazano za 7 dana', $status->scheduledThisWeek())
                ->description('Odobrene objave koje čekaju svoj termin')
                ->color('info')
                ->url(PostDraftResource::getUrl('index', ['tab' => 'scheduled'])),

            Stat::make('Nije uspjelo', $failed)
                ->description($failed > 0 ? 'Objave koje treba popraviti' : 'Nema neuspjelih objava')
                ->descriptionIcon($failed > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($failed > 0 ? 'danger' : 'gray')
                ->url(PostDraftResource::getUrl('index', ['tab' => 'failed'])),

            Stat::make('Veze koje traže pažnju', $accounts + $sources)
                ->description($this->connectionsHint($accounts, $sources))
                ->descriptionIcon($accounts + $sources > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($accounts + $sources > 0 ? 'danger' : 'gray')
                ->url($accounts > 0 ? SocialAccountResource::getUrl('index') : SourceResource::getUrl('index')),
        ];
    }

    private function connectionsHint(int $accounts, int $sources): string
    {
        $parts = array_filter([
            $accounts > 0 ? "{$accounts} račun(a) za ponovno povezivanje" : null,
            $sources > 0 ? "{$sources} izvor(a) s greškom" : null,
        ]);

        return $parts === [] ? 'Računi i izvori rade' : implode(' · ', $parts);
    }
}
