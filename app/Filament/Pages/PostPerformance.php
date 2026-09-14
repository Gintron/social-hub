<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\PostVariant;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Which posts get seen: average views per channel and format, per hour of posting and per item
 * priority (for job listings, the package). The campaign's formats and posting windows are set in
 * the panel; this is where to look before changing them.
 */
final class PostPerformance extends Page
{
    public ?int $brandId = null;

    public int $days = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Sadržaj';

    protected static ?string $navigationLabel = 'Učinak';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.post-performance';

    public function getTitle(): string
    {
        return 'Učinak objava';
    }

    /**
     * @return array<int|string, string>
     */
    public function getBrandOptions(): array
    {
        return ['' => 'Svi brendovi'] + Brand::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, list<array{label: string, posts: int, views: int|null, reach: int|null, interactions: int}>>
     */
    public function getBreakdowns(): array
    {
        $variants = $this->measured();
        $timezone = (string) config('hub.brand_default_timezone', 'Europe/Zagreb');

        return [
            'Kanal i format' => $this->summarise($variants->groupBy(fn (PostVariant $variant): string => $variant->platform->label().' · '.$variant->format()->label())),
            'Sat objave' => $this->summarise($variants
                ->groupBy(fn (PostVariant $variant): string => CarbonImmutable::parse($variant->published_at ?? $variant->manual_posted_at)->setTimezone($timezone)->format('H').':00')
                ->sortKeys()),
            'Prioritet stavke (paket)' => $this->summarise($variants
                ->groupBy(fn (PostVariant $variant): string => 'Prioritet '.($variant->draft?->contentItems->max('priority') ?? '—'))
                ->sortKeysDesc()),
        ];
    }

    /**
     * @return Collection<int, PostVariant>
     */
    public function getTopPosts(): Collection
    {
        return $this->measured()
            ->sortByDesc(fn (PostVariant $variant): int => (int) $variant->latestMetric?->views)
            ->take(10)
            ->values();
    }

    public function measuredCount(): int
    {
        return $this->measured()->count();
    }

    /**
     * Published channels with at least one reading, newest reading per channel.
     *
     * @return Collection<int, PostVariant>
     */
    private function measured(): Collection
    {
        $since = CarbonImmutable::now()->subDays(max(1, $this->days));

        return once(fn () => PostVariant::query()
            ->with(['latestMetric', 'draft.contentItems', 'draft.brand'])
            ->whereHas('latestMetric')
            ->where(fn ($query) => $query->where('published_at', '>=', $since)->orWhere('manual_posted_at', '>=', $since))
            ->when($this->brandId, fn ($query) => $query->whereHas('draft', fn ($draft) => $draft->where('brand_id', $this->brandId)))
            ->get());
    }

    /**
     * @param  Collection<string, Collection<int, PostVariant>>  $groups
     * @return list<array{label: string, posts: int, views: int|null, reach: int|null, interactions: int}>
     */
    private function summarise(Collection $groups): array
    {
        return $groups->map(function (Collection $group, string $label): array {
            $metrics = $group->map(fn (PostVariant $variant) => $variant->latestMetric);
            $views = $metrics->pluck('views')->filter(fn (?int $value): bool => $value !== null);
            $reach = $metrics->pluck('reach')->filter(fn (?int $value): bool => $value !== null);

            return [
                'label' => $label,
                'posts' => $group->count(),
                'views' => $views->isEmpty() ? null : (int) round($views->avg()),
                'reach' => $reach->isEmpty() ? null : (int) round($reach->avg()),
                'interactions' => (int) round($metrics->avg(fn ($metric): int => $metric->interactions())),
            ];
        })->values()->all();
    }
}
