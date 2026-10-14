<x-filament-panels::page>
    @php
        $num = fn (?int $value): string => $value === null ? '—' : number_format($value, 0, ',', '.');
    @endphp

    <div class="hub-toolbar">
        <span class="hub-toolbar__note">
            Zadnje očitanje po objavi. Brojke skuplja <code>hub:collect-metrics</code> svaki sat.
        </span>

        <div class="hub-toolbar__filters">
            <x-filament::input.wrapper class="hub-toolbar__filter">
                <x-filament::input.select wire:model.live="brandId" aria-label="Brend">
                    @foreach ($this->getBrandOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="days" aria-label="Razdoblje">
                    <option value="7">7 dana</option>
                    <option value="30">30 dana</option>
                    <option value="90">90 dana</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </div>

    @if ($this->unavailableMetricsCount() > 0)
        <x-filament::callout
            color="gray"
            icon="heroicon-o-information-circle"
            heading="TikTok: očitanja nisu dostupna za {{ $this->unavailableMetricsCount() }} objava"
            description="Hub trenutačno ne dohvaća metrike TikTok API-ja za Business. To nije nula pregleda, a ponovno povezivanje računa ne dodaje ovu mogućnost. Rezultate pogledaj u TikTok analitici."
        />
    @endif

    @if ($this->unmeasuredCount() > 0)
        <x-filament::callout
            color="warning"
            icon="heroicon-o-exclamation-triangle"
            heading="Nedostaju očitanja za objavljene kanale"
        >
            <x-slot name="description">
                @foreach ($this->getMeasurementGaps() as $gap)
                    <span>{{ $gap['label'] }}: {{ $gap['posts'] }}@unless ($loop->last) · @endunless</span>
                @endforeach
                <br>
                Objave su starije od {{ \App\Actions\CollectPostMetrics::FRESH_EVERY_HOURS }} sati, ali platforma nije vratila nijednu metriku.
                Provjeri dozvole za uvide i ponovno poveži pogođeni račun.
            </x-slot>
        </x-filament::callout>
    @endif

    @if ($this->measuredCount() === 0)
        <x-filament::section>
            <x-filament::empty-state
                icon="heroicon-o-chart-bar"
                heading="Još nema očitanja za ovo razdoblje"
                :contained="false"
            >
                <x-slot name="description">
                    Nedostajuća metrika nije nula pregleda. Ako očitanja izostanu dulje od dana, provjeri dozvole za uvide:
                    <code>instagram_manage_insights</code>, <code>read_insights</code>, TikTok Developer <code>video.list</code>,
                    YouTube <code>youtube.readonly</code>.
                </x-slot>
            </x-filament::empty-state>
        </x-filament::section>
    @else
        <div class="hub-stack">
            @foreach ($this->getBreakdowns() as $title => $rows)
                @php($max = max(1, ...array_map(fn (array $row): int => (int) $row['views'], $rows)))
                <x-filament::section :heading="$title" compact>
                    <div class="hub-table-wrap">
                        <table class="hub-table hub-table--fixed">
                            <colgroup>
                                <col style="width:28%">
                                <col style="width:9%">
                                <col>
                                <col style="width:12%">
                                <col style="width:14%">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>&nbsp;</th>
                                    <th class="is-num">Objava</th>
                                    <th>Ø pregleda</th>
                                    <th class="is-num">Ø doseg</th>
                                    <th class="is-num">Ø interakcija</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td class="is-num">{{ $row['posts'] }}</td>
                                        <td>
                                            <div class="hub-bar">
                                                <div class="hub-bar__fill" style="--hub-bar: {{ (int) round(100 * (int) $row['views'] / $max) }}%"></div>
                                                <span>{{ $num($row['views']) }}</span>
                                            </div>
                                        </td>
                                        <td class="is-num">{{ $num($row['reach']) }}</td>
                                        <td class="is-num">{{ $num($row['interactions']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endforeach

            <x-filament::section heading="Najgledanije objave" compact>
                <div class="hub-table-wrap">
                    <table class="hub-table">
                        <tbody>
                            @foreach ($this->getTopPosts() as $variant)
                                <tr>
                                    <td>
                                        <x-filament::link :href="\App\Filament\Resources\PostDrafts\PostDraftResource::getUrl('edit', ['record' => $variant->post_draft_id])">
                                            {{ \Illuminate\Support\Str::limit((string) $variant->draft?->title, 60) }}
                                        </x-filament::link>
                                        <div class="hub-table__sub">{{ $variant->draft?->brand?->name }}</div>
                                    </td>
                                    <td class="is-num">{{ $variant->platform->shortLabel() }} · {{ $variant->format()->label() }}</td>
                                    <td class="is-num">{{ $num($variant->latestMetric->views) }} pregleda</td>
                                    <td class="is-num">{{ $num($variant->latestMetric->interactions()) }} interakcija</td>
                                    <td class="is-num">
                                        @if ($variant->permalink)
                                            <x-filament::link :href="$variant->permalink" target="_blank" rel="noopener" size="sm" icon="heroicon-m-arrow-top-right-on-square" icon-position="after">Otvori</x-filament::link>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
