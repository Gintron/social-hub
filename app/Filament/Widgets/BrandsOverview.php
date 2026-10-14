<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AccountStatus;
use App\Enums\DraftStatus;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Support\HubStatus;
use App\Models\Brand;
use App\Models\PostVariant;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * One row per brand: is it connected, is it waiting on someone, when did it last go out. It is what a
 * person with several brands needs at nine in the morning, and it shows a brand that was set up
 * halfway (a source and no account) before it fails a post. With one brand the stats say all of it.
 */
final class BrandsOverview extends TableWidget
{
    protected static ?int $sort = -15;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Brand::query()->count() > 1;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Brendovi')
            ->query(fn () => Brand::query()
                ->withCount([
                    'sources as sources_count' => fn ($query) => $query->where('enabled', true),
                    'sources as sources_problem_count' => fn ($query) => HubStatus::sourcesNeedingAttention($query),
                    // Every account that is not switched off: one that needs a reconnect is still one of the brand's.
                    'socialAccounts as accounts_count' => fn ($query) => $query->where('status', '!=', AccountStatus::Disabled->value),
                    'socialAccounts as accounts_problem_count' => fn ($query) => HubStatus::accountsNeedingAttention($query),
                    'postDrafts as pending_count' => fn ($query) => $query->where('status', DraftStatus::PendingApproval->value),
                    'postDrafts as scheduled_count' => fn ($query) => $query
                        ->whereIn('status', [DraftStatus::Approved->value, DraftStatus::Scheduled->value])
                        ->whereBetween('scheduled_at', [now(), now()->addWeek()]),
                ])
                ->addSelect(['last_published_at' => PostVariant::query()
                    ->select('post_variants.published_at')
                    ->join('post_drafts', 'post_drafts.id', '=', 'post_variants.post_draft_id')
                    ->whereColumn('post_drafts.brand_id', 'brands.id')
                    ->whereNotNull('post_variants.published_at')
                    ->orderByDesc('post_variants.published_at')
                    ->limit(1)])
                ->orderBy('name'))
            ->columns([
                TextColumn::make('name')->label('Brend')->weight('semibold'),
                TextColumn::make('sources_count')->label('Izvori')->badge()
                    ->formatStateUsing(fn (int $state, Brand $record): string => match (true) {
                        $state === 0 => 'nema',
                        $record->sources_problem_count > 0 => "{$record->sources_problem_count} od {$state} s greškom",
                        default => (string) $state,
                    })
                    ->color(fn (int $state, Brand $record): string => match (true) {
                        $state === 0 => 'warning',
                        $record->sources_problem_count > 0 => 'danger',
                        default => 'success',
                    }),
                TextColumn::make('accounts_count')->label('Računi')->badge()
                    ->formatStateUsing(fn (int $state, Brand $record): string => match (true) {
                        $state === 0 => 'nema',
                        $record->accounts_problem_count > 0 => "{$record->accounts_problem_count} od {$state} traži pažnju",
                        default => (string) $state,
                    })
                    ->color(fn (int $state, Brand $record): string => match (true) {
                        $state === 0 => 'warning',
                        $record->accounts_problem_count > 0 => 'danger',
                        default => 'success',
                    }),
                TextColumn::make('pending_count')->label('Čeka')->badge()->alignCenter()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('scheduled_count')->label('Zakazano za 7 dana')->alignCenter()->visibleFrom('xl'),
                TextColumn::make('last_published_at')->label('Zadnja objava')->since()->placeholder('još nijedna')->visibleFrom('xl'),
            ])
            ->recordUrl(fn (Brand $record): string => BrandResource::getUrl('edit', ['record' => $record]))
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedSwatch)
            ->emptyStateHeading('Nema brendova');
    }
}
