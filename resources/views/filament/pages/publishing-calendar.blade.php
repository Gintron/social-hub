<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:8px">
                <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previousMonth" label="Prethodni mjesec" />
                <span style="font-size:16px;font-weight:600;min-width:11rem;text-align:center">{{ $this->getMonthLabel() }}</span>
                <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="nextMonth" label="Sljedeći mjesec" />
                <x-filament::button size="xs" color="gray" wire:click="today">Danas</x-filament::button>
            </div>
            <select wire:model.live="brandId"
                    class="fi-select-input rounded-lg border-none bg-white py-2 pe-8 ps-3 text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-gray-900 dark:text-white dark:ring-white/20">
                @foreach ($this->getBrandOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin-top:16px">
            @foreach (['pon', 'uto', 'sri', 'čet', 'pet', 'sub', 'ned'] as $weekday)
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6;padding:2px 4px">{{ $weekday }}</div>
            @endforeach

            @foreach ($this->getWeeks() as $week)
                @foreach ($week as $day)
                    <div @class(['fi-calendar-day'])
                         style="min-height:104px;border-radius:10px;padding:6px;border:1px solid rgba(128,128,128,.2);
                                {{ $day['inMonth'] ? '' : 'opacity:.45;' }}
                                {{ $day['isToday'] ? 'outline:2px solid rgb(20,184,166);outline-offset:-2px;' : '' }}">
                        <div style="font-size:12px;font-weight:600;opacity:.7;margin-bottom:4px">{{ $day['date']->format('j') }}</div>

                        @foreach ($day['drafts'] as $draft)
                            @php
                                $color = match ($draft->status->getColor()) {
                                    'success' => '#059669',
                                    'warning' => '#d97706',
                                    'danger' => '#dc2626',
                                    'info' => '#0284c7',
                                    default => '#6b7280',
                                };
                            @endphp
                            <a href="{{ \App\Filament\Resources\PostDrafts\PostDraftResource::getUrl('edit', ['record' => $draft]) }}"
                               title="{{ $draft->brand?->name }} · {{ $draft->status->label() }} · {{ $draft->variants->pluck('platform')->map(fn ($p) => $p->label())->implode(', ') }}"
                               style="display:block;font-size:11px;line-height:1.3;text-decoration:none;color:inherit;
                                      border-left:3px solid {{ $color }};padding:2px 4px;margin-bottom:3px;border-radius:3px;
                                      background:rgba(128,128,128,.08);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <strong>{{ $this->draftTime($draft) }}</strong> {{ $draft->title }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            @endforeach
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:14px;margin-top:14px;font-size:11px;opacity:.75">
            @foreach (\App\Enums\DraftStatus::cases() as $status)
                @continue($status === \App\Enums\DraftStatus::Discarded)
                @php
                    $color = match ($status->getColor()) {
                        'success' => '#059669', 'warning' => '#d97706', 'danger' => '#dc2626', 'info' => '#0284c7', default => '#6b7280',
                    };
                @endphp
                <span style="display:inline-flex;align-items:center;gap:5px">
                    <span style="width:9px;height:9px;border-radius:2px;background:{{ $color }}"></span>{{ $status->label() }}
                </span>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
