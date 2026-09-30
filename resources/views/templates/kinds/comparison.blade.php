@extends('templates._layout')

{{--
  A comparison's ranking (Social Feed v1, kind=comparison): one row per provider, the cheapest first. The
  ranking is drawn, not only written: every row is a bar as long as its figure, so the cheapest is the
  shortest and the yellow one, and how much dearer the others are shows before a number is read. Whose
  row it is has to be seen at a glance, so every row carries the provider's own plaque. Without a plain
  number to measure (see ComparisonFrame::barWidths) the rows are the same length and it is a list. On
  9:16 the app covers the top and the bottom, so the list sits in the middle band; the other formats keep
  the brand's footer and scale the same design.
--}}
@php
    $g = \App\Rendering\ComparisonFrame::layout($width, $height);
    // A square goes to Facebook and Instagram, where the caption lists every row anyway.
    $rows = array_slice($item['rows'] ?? [], 0, $g['square'] ? 4 : 5);
    $bars = \App\Rendering\ComparisonFrame::barWidths($rows);
    // Fewer rows are taller, so a short ranking does not leave a hole under it.
    $rowHeight = count($rows) <= 3 ? 220 : (count($rows) === 4 ? 200 : 178);
@endphp

@section('styles')
  .cmp { flex: 1; min-height: 0; overflow: hidden; display: flex; flex-direction: column; justify-content: center; color: #fff;
         padding: {{ $g['padTop'] }}px 60px {{ $g['padBottom'] }}px;
         background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .cmp__in { zoom: {{ $g['zoom'] }}; display: flex; flex-direction: column; gap: 22px; }
  .cmp-title { padding: 0 10px 6px; font-size: 58px; font-weight: 900; line-height: 1.05; letter-spacing: -1px; text-wrap: balance;
               overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
@include('templates.partials.comparison-plaques')
  .rows { display: flex; flex-direction: column; gap: 16px; }
  .row { position: relative; height: {{ $rowHeight }}px; border-radius: 30px; background: rgba(255,255,255,.14); border: 2px solid rgba(255,255,255,.22); }
  .row__bar { position: absolute; left: -2px; top: -2px; bottom: -2px; display: flex; align-items: center; gap: 18px; padding: 0 30px 0 22px;
              border-radius: 30px; color: {{ $brand['text'] }}; background: rgba(255,255,255,.94); box-shadow: 0 10px 26px rgba(0,0,0,.16); }
  .row.first .row__bar { background: #ffd84d; box-shadow: 0 16px 36px rgba(0,0,0,.3); }
  .row__rank { flex: none; width: 44px; text-align: center; font-size: 46px; font-weight: 900; color: {{ $brand['muted'] }}; }
  .row.first .row__rank { color: {{ $brand['text'] }}; }
  .row__who { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
  .row__product { overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
                  font-size: 25px; line-height: 1.15; font-weight: 700; color: {{ $brand['muted'] }}; }
  .row.first .row__product { color: rgba(31,42,33,.75); }
  .row__figure { flex: none; text-align: right; }
  .row__value { display: block; font-size: 60px; font-weight: 900; letter-spacing: -1px; line-height: 1; white-space: nowrap; }
  .row__pack { display: block; margin-top: 6px; font-size: 24px; font-weight: 700; color: {{ $brand['muted'] }}; }
  .row.first .row__pack { color: rgba(31,42,33,.7); }
  .row__tag { position: absolute; right: 26px; top: -18px; z-index: 3; padding: 7px 18px; border-radius: 999px; font-size: 24px; font-weight: 900;
              letter-spacing: 3px; color: #ffd84d; background: {{ $brand['text'] }}; }
  .cmp-valid { padding-left: 10px; font-size: 26px; font-weight: 700; color: rgba(255,255,255,.85); }
@endsection

@section('card')
  <div class="cmp">
    <div class="cmp__in">
      <div class="cmp-title">{{ $item['title'] }}</div>
      <div class="rows">
        @foreach($rows as $index => $row)
          <div class="row {{ $index === 0 ? 'first' : '' }}">
            @if($index === 0)
              <div class="row__tag">NAJJEFTINIJE</div>
            @endif
            <div class="row__bar" style="width: calc({{ $bars[$index] ?? 100 }}% + 4px);">
              <div class="row__rank">{{ $index + 1 }}.</div>
              <div class="row__who">
                <div class="cmp-pl cmp-pl--sm">@include('templates.partials.provider', ['item' => ['provider' => $row['provider']], 'name' => true])</div>
                @if($row['title'])
                  <div class="row__product">{{ $row['title'] }}</div>
                @endif
              </div>
              <div class="row__figure">
                <span class="row__value">{{ $row['value'] }}</span>
                @if($row['price'])
                  <span class="row__pack">pakiranje {{ $row['price'] }}</span>
                @endif
              </div>
            </div>
          </div>
        @endforeach
      </div>
      @if($item['expires_at'])
        <div class="cmp-valid">Cijene iz letaka, vrijede do {{ $item['expires_at'] }}</div>
      @endif
    </div>
  </div>
  @unless($g['story'])
    @include('templates.partials.footer', ['cta' => $item['cta_label'] ?? null])
  @endunless
@endsection
