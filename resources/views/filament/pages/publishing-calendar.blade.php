<x-filament-panels::page>
    @php
        $weekdays = ['pon', 'uto', 'sri', 'čet', 'pet', 'sub', 'ned'];
        $weeks = $this->getWeeks();
        $inMonthCount = collect($weeks)->flatten(1)->where('inMonth', true)->sum(fn (array $day): int => $day['drafts']->count());
        $visiblePerDay = 4;
    @endphp

    <div class="hub-toolbar">
        <div class="hub-toolbar__nav">
            <x-filament::icon-button icon="heroicon-m-chevron-left" wire:click="previousMonth" label="Prethodni mjesec" />
            <h2 class="hub-toolbar__title">{{ $this->getMonthLabel() }}</h2>
            <x-filament::icon-button icon="heroicon-m-chevron-right" wire:click="nextMonth" label="Sljedeći mjesec" />
            <x-filament::button size="sm" color="gray" wire:click="today">Danas</x-filament::button>
        </div>

        <x-filament::input.wrapper class="hub-toolbar__filter">
            <x-filament::input.select wire:model.live="brandId" aria-label="Brend">
                @foreach ($this->getBrandOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    <x-filament::section>
        @if ($inMonthCount === 0)
            <x-filament::empty-state
                icon="heroicon-o-calendar"
                heading="Nema objava u ovom mjesecu"
                description="Ovdje se vide objave koje su zakazane i one koje su već izašle."
                :contained="false"
            />
        @else
            <div class="hub-cal">
                <div class="hub-cal__weekdays" aria-hidden="true">
                    @foreach ($weekdays as $weekday)
                        <div>{{ $weekday }}</div>
                    @endforeach
                </div>

                <div class="hub-cal__grid">
                    @foreach ($weeks as $week)
                        @foreach ($week as $day)
                            @php
                                $shown = $day['drafts']->take($visiblePerDay);
                                $rest = $day['drafts']->slice($visiblePerDay);
                            @endphp
                            <div @class([
                                'hub-cal__day',
                                'hub-cal__day--outside' => ! $day['inMonth'],
                                'hub-cal__day--today' => $day['isToday'],
                                'hub-cal__day--empty' => $day['drafts']->isEmpty(),
                            ])>
                                <div class="hub-cal__date">
                                    {{ $day['date']->format('j') }}<span class="hub-cal__weekday-inline">{{ $weekdays[$day['date']->dayOfWeekIso - 1] }}</span>
                                </div>

                                @foreach ($shown as $draft)
                                    <a href="{{ \App\Filament\Resources\PostDrafts\PostDraftResource::getUrl('edit', ['record' => $draft]) }}"
                                       class="hub-cal__item"
                                       data-tone="{{ $draft->status->getColor() }}"
                                       title="{{ $draft->brand?->name }} · {{ $draft->status->label() }} · {{ $draft->variants->pluck('platform')->map(fn ($platform) => $platform->shortLabel())->implode(', ') }}">
                                        <span class="hub-cal__text"><span class="hub-cal__time">{{ $this->draftTime($draft) }}</span> {{ $draft->title }}</span>
                                        @unless ($brandId)
                                            <span class="hub-cal__brand">{{ $draft->brand?->name }}</span>
                                        @endunless
                                    </a>
                                @endforeach

                                @if ($rest->isNotEmpty())
                                    <span class="hub-cal__more" title="{{ $rest->pluck('title')->implode(' · ') }}">+{{ $rest->count() }} još</span>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endif

        <div class="hub-legend">
            @foreach (\App\Enums\DraftStatus::cases() as $status)
                @continue($status === \App\Enums\DraftStatus::Discarded)
                <span class="hub-legend__item" data-tone="{{ $status->getColor() }}">{{ $status->label() }}</span>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
