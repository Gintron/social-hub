<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostDrafts\Pages;

use App\Enums\DraftStatus;
use App\Filament\Resources\PostDrafts\PostDraftResource;
use App\Models\PostDraft;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListPostDrafts extends ListRecords
{
    protected static string $resource = PostDraftResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Čeka odobrenje')
                ->badge(PostDraft::query()->where('status', DraftStatus::PendingApproval->value)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', DraftStatus::PendingApproval->value)),
            'scheduled' => Tab::make('Zakazano')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [DraftStatus::Approved->value, DraftStatus::Scheduled->value, DraftStatus::Publishing->value])),
            'published' => Tab::make('Objavljeno')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [DraftStatus::Published->value, DraftStatus::PartiallyPublished->value])),
            'failed' => Tab::make('Neuspjelo')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [DraftStatus::Failed->value, DraftStatus::Skipped->value])),
            'all' => Tab::make('Sve'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
