<x-filament-panels::page>
    @php
        $box = 'fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
        $select = 'fi-select-input rounded-lg border-none bg-white py-2 pe-8 ps-3 text-sm shadow-sm ring-1 ring-gray-950/10 dark:bg-gray-900 dark:text-white dark:ring-white/20';
        $num = fn (?int $value): string => $value === null ? '—' : number_format($value, 0, ',', '.');
    @endphp

    <div class="{{ $box }}" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
        <span style="font-size:13px;opacity:.75">
            Zadnje očitanje po objavi. Brojke skuplja <code>hub:collect-metrics</code> svaki sat.
        </span>
        <div style="display:flex;gap:8px">
            <select wire:model.live="brandId" class="{{ $select }}">
                @foreach ($this->getBrandOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="days" class="{{ $select }}">
                <option value="7">7 dana</option>
                <option value="30">30 dana</option>
                <option value="90">90 dana</option>
            </select>
        </div>
    </div>

    @if ($this->unmeasuredCount() > 0)
        <div class="{{ $box }}" style="font-size:14px;line-height:1.55;border-left:4px solid rgb(245,158,11)">
            <div style="font-weight:600;margin-bottom:4px">Nedostaju očitanja za objavljene kanale</div>
            <div>
                @foreach ($this->getMeasurementGaps() as $gap)
                    <span style="display:inline-block;margin-right:12px">{{ $gap['label'] }}: {{ $gap['posts'] }}</span>
                @endforeach
            </div>
            <div style="margin-top:4px;opacity:.75">
                Objave su starije od {{ \App\Actions\CollectPostMetrics::FRESH_EVERY_HOURS }} sati, ali platforma nije vratila nijednu metriku.
                Provjeri dozvole za uvide i ponovno poveži pogođeni račun.
            </div>
        </div>
    @endif

    @if ($this->measuredCount() === 0)
        <div class="{{ $box }}" style="font-size:14px;line-height:1.5">
            Još nema očitanja za ovo razdoblje. Pregledi stižu nekoliko sati nakon objave; ako ih nema ni
            nakon dana, računima nedostaju dozvole za uvide (<code>instagram_manage_insights</code>,
            <code>read_insights</code>, TikTok <code>video.list</code>) — dodaj ih i ponovno poveži račune.
        </div>
    @else
        @foreach ($this->getBreakdowns() as $title => $rows)
            @php($max = max(1, ...array_map(fn (array $row): int => (int) $row['views'], $rows)))
            <div class="{{ $box }}" style="overflow-x:auto">
                <div style="font-weight:600;margin-bottom:10px">{{ $title }}</div>
                <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr style="text-align:left;opacity:.6;font-size:11px;text-transform:uppercase;letter-spacing:.04em">
                            <th style="padding:6px 8px">&nbsp;</th>
                            <th style="padding:6px 8px;text-align:right">Objava</th>
                            <th style="padding:6px 8px;width:40%">Ø pregleda</th>
                            <th style="padding:6px 8px;text-align:right">Ø doseg</th>
                            <th style="padding:6px 8px;text-align:right">Ø interakcija</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-top:1px solid rgba(128,128,128,.15)">
                                <td style="padding:6px 8px;white-space:nowrap">{{ $row['label'] }}</td>
                                <td style="padding:6px 8px;text-align:right">{{ $row['posts'] }}</td>
                                <td style="padding:6px 8px">
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <div style="height:8px;border-radius:4px;background:rgb(20,184,166);width:{{ (int) round(100 * (int) $row['views'] / $max) }}%;min-width:2px"></div>
                                        <span style="white-space:nowrap">{{ $num($row['views']) }}</span>
                                    </div>
                                </td>
                                <td style="padding:6px 8px;text-align:right">{{ $num($row['reach']) }}</td>
                                <td style="padding:6px 8px;text-align:right">{{ $num($row['interactions']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach

        <div class="{{ $box }}" style="overflow-x:auto">
            <div style="font-weight:600;margin-bottom:10px">Najgledanije objave</div>
            <table style="width:100%;border-collapse:collapse;font-size:13px">
                <tbody>
                    @foreach ($this->getTopPosts() as $variant)
                        <tr style="border-top:1px solid rgba(128,128,128,.15)">
                            <td style="padding:6px 8px">
                                <a href="{{ \App\Filament\Resources\PostDrafts\PostDraftResource::getUrl('edit', ['record' => $variant->post_draft_id]) }}" style="text-decoration:none;color:inherit;font-weight:600">
                                    {{ \Illuminate\Support\Str::limit((string) $variant->draft?->title, 60) }}
                                </a>
                                <div style="font-size:11px;opacity:.6">{{ $variant->draft?->brand?->name }}</div>
                            </td>
                            <td style="padding:6px 8px;white-space:nowrap">{{ $variant->platform->label() }} · {{ $variant->format()->label() }}</td>
                            <td style="padding:6px 8px;text-align:right;white-space:nowrap">{{ $num($variant->latestMetric->views) }} pregleda</td>
                            <td style="padding:6px 8px;text-align:right;white-space:nowrap">{{ $num($variant->latestMetric->interactions()) }} interakcija</td>
                            <td style="padding:6px 8px;text-align:right">
                                @if ($variant->permalink)
                                    <a href="{{ $variant->permalink }}" target="_blank" rel="noopener" style="font-size:12px">Otvori ↗</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
