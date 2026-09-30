@extends('templates._layout')

{{--
  First slide of a comparison (Social Feed v1, kind=comparison): the question, then who answers it.
  The cheapest shop gets one yellow block of its own (the same signal as the deal's price block): its
  plaque, the figure the shops are ranked by, and the leaflet picture of its product as the proof. The
  next two follow as tiles with their own plaques, so the ranking reads from the logos before a single
  figure is read. A shop named only in a line of text is what made the first hook look like a price list.
  On 9:16 the app covers the top and the bottom, so it sits in the middle band; the other formats keep
  the brand's footer and scale the same design (App\Rendering\ComparisonFrame).
--}}
@php
    $rows = $item['rows'] ?? [];
    $first = $rows[0] ?? null;
    $next = array_slice($rows, 1, 2);
    $parts = $first ? \App\Rendering\TemplateData::priceParts($first['value']) : null;
    // The feed's first picture is the first row's product whole; the row's own is only a thumbnail of it.
    $hasPrimary = ! empty($item['primary_image']);
    $hero = $item['primary_image'] ?? ($first['image'] ?? null);
    $shape = $hasPrimary ? ($item['primary_shape'] ?? null) : ['ratio' => 1.0, 'cut' => false];
    $cut = (bool) ($shape['cut'] ?? true);
    $g = \App\Rendering\ComparisonFrame::layout($width, $height, $shape);
    $proof = implode(' · ', array_filter([$first['title'] ?? null, $first['price'] ?? null]));
@endphp

@section('styles')
  .cmp { flex: 1; min-height: 0; overflow: hidden; display: flex; flex-direction: column; justify-content: center; color: #fff;
         padding: {{ $g['padTop'] }}px 60px {{ $g['padBottom'] }}px;
         background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .cmp__in { zoom: {{ $g['zoom'] }}; display: flex; flex-direction: column; gap: 30px; }
  .cmp-title { font-size: 76px; font-weight: 900; line-height: 1.04; letter-spacing: -1.5px; text-wrap: balance; padding: 0 10px;
               overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
@include('templates.partials.leaflet-styles')
@include('templates.partials.comparison-plaques')
  .cmp-win { position: relative; padding: 38px 48px 40px; color: {{ $brand['text'] }}; background: #ffd84d; border-radius: 40px;
             transform: rotate(-1.2deg); box-shadow: 0 26px 60px rgba(0,0,0,.4); }
  .cmp-win__top { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
  .cmp-win__who { display: flex; flex-direction: column; align-items: flex-start; gap: 20px; padding-top: 6px; min-width: 0; }
  .cmp-ribbon { padding: 10px 24px; border-radius: 999px; font-size: 28px; font-weight: 900; letter-spacing: 3px; color: #ffd84d; background: {{ $brand['text'] }}; }
  .cmp-win__pic { flex: none; margin: -6px -8px 0 0; transform: rotate(4deg); }
  .cmp-win__price { display: flex; align-items: baseline; gap: 14px; margin-top: 14px; }
  .cmp-win__price .n { font-size: 200px; font-weight: 900; letter-spacing: -8px; line-height: 1; }
  .cmp-win__price .n--text { font-size: 110px; letter-spacing: -2px; }
  .cmp-win__price .u { font-size: 76px; font-weight: 800; }
  .cmp-win__proof { margin-top: 8px; font-size: 30px; font-weight: 700; color: rgba(31,42,33,.75); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .cmp-next { display: flex; gap: 20px; }
  .cmp-rk { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 10px; padding: 22px 28px; color: {{ $brand['text'] }}; background: #fff;
            border-radius: 30px; box-shadow: 0 12px 30px rgba(0,0,0,.18); }
  .cmp-rk__head { display: flex; align-items: center; gap: 12px; min-width: 0; }
  .cmp-rk__head .r { flex: none; font-size: 38px; font-weight: 900; color: {{ $brand['muted'] }}; }
  .cmp-rk__value { font-size: 56px; font-weight: 900; letter-spacing: -1px; line-height: 1; white-space: nowrap; }
  .cmp-badges { display: flex; gap: 14px; flex-wrap: wrap; }
  .cmp-badges span { padding: 10px 24px; border-radius: 999px; font-size: 28px; font-weight: 800; background: rgba(255,255,255,.2); }
@endsection

@section('card')
  <div class="cmp">
    <div class="cmp__in">
      <div class="cmp-title">{{ $item['title'] }}</div>

      @if($first)
        <div class="cmp-win">
          <div class="cmp-win__top">
            <div class="cmp-win__who">
              <div class="cmp-ribbon">NAJJEFTINIJE</div>
              <div class="cmp-pl cmp-pl--md">@include('templates.partials.provider', ['item' => ['provider' => $first['provider']], 'name' => true])</div>
            </div>
            @if($hero)
              <div class="cmp-win__pic" style="width: {{ $g['tw'] }}px; height: {{ $g['th'] }}px;">
                @include('templates.partials.leaflet', ['src' => $hero, 'cut' => $cut])
              </div>
            @endif
          </div>
          <div class="cmp-win__price">
            @if($parts)
              <span class="n">{{ $parts['whole'] }},{{ $parts['cents'] }}</span><span class="u">{{ $parts['unit'] }}</span>
            @else
              <span class="n n--text">{{ $first['value'] }}</span>
            @endif
          </div>
          @if($proof !== '')
            <div class="cmp-win__proof">{{ $proof }}</div>
          @endif
        </div>
      @endif

      @if($next !== [])
        <div class="cmp-next">
          @foreach($next as $index => $row)
            <div class="cmp-rk">
              <div class="cmp-rk__head">
                <span class="r">{{ $index + 2 }}.</span>
                <span class="cmp-pl cmp-pl--sm">@include('templates.partials.provider', ['item' => ['provider' => $row['provider']], 'name' => true])</span>
              </div>
              <div class="cmp-rk__value">{{ $row['value'] }}</div>
            </div>
          @endforeach
        </div>
      @endif

      @if(! empty($item['badges']))
        <div class="cmp-badges">
          @foreach(array_slice($item['badges'], 0, 2) as $badge)
            <span>{{ $badge }}</span>
          @endforeach
        </div>
      @endif
    </div>
  </div>
  @unless($g['story'])
    @include('templates.partials.footer', ['cta' => $item['cta_label'] ?? null])
  @endunless
@endsection
