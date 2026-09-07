<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DraftStatus;
use App\Models\Brand;
use App\Models\PostDraft;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Month view of what is going out and what already went out, so a week's posting is visible at a
 * glance instead of reconstructed from a sorted table.
 */
final class PublishingCalendar extends Page
{
    /**
     * Month being shown, as Y-m.
     */
    public string $month = '';

    public ?int $brandId = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Sadržaj';

    protected static ?string $navigationLabel = 'Kalendar';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.publishing-calendar';

    public function mount(): void
    {
        $this->month = $this->timezoneNow()->format('Y-m');
    }

    public function getTitle(): string
    {
        return 'Kalendar objava';
    }

    public function previousMonth(): void
    {
        $this->month = $this->firstOfMonth()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->firstOfMonth()->addMonth()->format('Y-m');
    }

    public function today(): void
    {
        $this->month = $this->timezoneNow()->format('Y-m');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBrandOptions(): array
    {
        return ['' => 'Svi brendovi'] + Brand::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function getMonthLabel(): string
    {
        $months = [1 => 'siječanj', 'veljača', 'ožujak', 'travanj', 'svibanj', 'lipanj', 'srpanj', 'kolovoz', 'rujan', 'listopad', 'studeni', 'prosinac'];
        $first = $this->firstOfMonth();

        return $months[(int) $first->format('n')].' '.$first->format('Y');
    }

    /**
     * Weeks of the displayed month, each a list of day cells (Monday first, padded to whole weeks).
     *
     * @return list<list<array{date: CarbonImmutable, inMonth: bool, isToday: bool, drafts: Collection<int, PostDraft>}>>
     */
    public function getWeeks(): array
    {
        $first = $this->firstOfMonth();
        $start = $first->startOfWeek(CarbonImmutable::MONDAY);
        $end = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $drafts = $this->draftsBetween($start, $end);
        $today = $this->timezoneNow()->toDateString();

        $weeks = [];
        $week = [];

        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $week[] = [
                'date' => $day,
                'inMonth' => $day->format('Y-m') === $this->month,
                'isToday' => $day->toDateString() === $today,
                'drafts' => $drafts->get($day->toDateString(), collect()),
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        return $weeks;
    }

    public function draftTime(PostDraft $draft): string
    {
        $at = $draft->scheduled_at ?? $draft->variants->max('published_at') ?? $draft->created_at;

        return CarbonImmutable::parse($at)->setTimezone($this->timezone())->format('H:i');
    }

    /**
     * A draft's calendar date is when it is due, or when it went out.
     *
     * @return Collection<string, Collection<int, PostDraft>>
     */
    private function draftsBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $timezone = $this->timezone();

        return PostDraft::query()
            ->with(['brand', 'variants'])
            ->whereNot('status', DraftStatus::Discarded->value)
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('scheduled_at', [$start->setTimezone('UTC'), $end->endOfDay()->setTimezone('UTC')])
                    ->orWhereHas('variants', fn ($q) => $q->whereBetween('published_at', [$start->setTimezone('UTC'), $end->endOfDay()->setTimezone('UTC')]));
            })
            ->when($this->brandId, fn ($query) => $query->where('brand_id', $this->brandId))
            ->get()
            ->groupBy(function (PostDraft $draft) use ($timezone): string {
                $at = $draft->scheduled_at ?? $draft->variants->max('published_at');

                return CarbonImmutable::parse($at ?? $draft->created_at)->setTimezone($timezone)->toDateString();
            });
    }

    private function firstOfMonth(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01', $this->timezone())->startOfDay();
    }

    private function timezoneNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    private function timezone(): string
    {
        return (string) config('hub.brand_default_timezone', 'Europe/Zagreb');
    }
}
