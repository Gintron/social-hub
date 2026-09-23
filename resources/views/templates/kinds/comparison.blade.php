@extends('templates._layout')

{{--
  A comparison's ranking (Social Feed v1, kind=comparison): one row per provider, the first one
  first. The figure the rows are ranked by is the largest thing in each row; the product and its
  pack price sit under the provider as the proof. Whose row it is has to be seen at a glance, so
  every row carries the provider's own plaque, not a line of text. On 9:16 the app covers the top
  and the bottom, so the list sits in the middle band.
--}}
@php
    $story = $height > 1500;
    $square = $height <= 1080;
    // A square has room for four rows; it goes to Facebook and Instagram, where the caption lists all of them.
    $rows = array_slice($item['rows'] ?? [], 0, $square ? 4 : 5);
    // Four rows get the height five would share, so a short ranking does not leave a hole under it.
    $rowHeight = $square ? 132 : (count($rows) <= 4 ? 176 : 150);
@endphp

@section('styles')
  .cmp { flex: 1; min-height: 0; display: flex; flex-direction: column; gap: {{ $square ? 16 : 22 }}px; overflow: hidden;
         padding: {{ $story ? '230px 52px 32px' : ($square ? '36px 52px 22px' : '48px 52px 28px') }};
         background: {{ $brand['surface'] }}; }
  .cmp .head { display: flex; flex-direction: column; gap: 14px; }
  .cmp .badges { position: static; max-width: 100%; }
  .cmp .title { font-size: {{ $story ? 60 : ($square ? 48 : 54) }}px; -webkit-line-clamp: 2; }
  .rows { display: flex; flex-direction: column; gap: {{ $square ? 12 : 16 }}px; }
  .row { display: flex; align-items: center; gap: 20px; height: {{ $rowHeight }}px; padding: 0 26px 0 18px; background: #fff;
         border-radius: 26px; box-shadow: 0 6px 18px rgba(0,0,0,.06); border: 5px solid transparent; }
  .row.first { border-color: {{ $brand['primary'] }}; }
  .row .rank { flex: 0 0 52px; font-size: 42px; font-weight: 900; color: {{ $brand['muted'] }}; text-align: center; }
  .row.first .rank { color: {{ $brand['primary'] }}; }
  .row .thumb { flex: 0 0 {{ $rowHeight - 34 }}px; height: {{ $rowHeight - 34 }}px; border-radius: 16px; overflow: hidden; background: #fff; }
  .row .thumb img { width: 100%; height: 100%; object-fit: contain; display: block; }
  .row .who { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: 6px; }
  .row .who .provider { background: transparent; box-shadow: none; padding: 0; border-radius: 0; max-width: 100%; gap: 14px; }
  .row .who .provider img { height: {{ $square ? 42 : 48 }}px; max-width: 250px; }
  .row .who .provider--compact img { height: {{ $square ? 56 : 62 }}px; max-width: 80px; }
  .row .who .provider .name { font-size: {{ $square ? 34 : 38 }}px; }
  .row .product { font-size: {{ $square ? 22 : 25 }}px; color: {{ $brand['muted'] }}; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .row .figure { flex: 0 0 auto; text-align: right; display: flex; flex-direction: column; gap: 4px; }
  .row .figure .value { font-size: {{ $square ? 44 : 50 }}px; font-weight: 900; letter-spacing: -1px; line-height: 1; color: {{ $brand['text'] }}; white-space: nowrap; }
  .row.first .figure .value { color: {{ $brand['primary'] }}; }
  .row .figure .pack { font-size: 24px; color: {{ $brand['muted'] }}; font-weight: 600; white-space: nowrap; }
  .cmp .valid { margin-top: auto; }
@endsection

@section('card')
  <div class="cmp">
    <div class="head">
      @if(!empty($item['badges']))
        <div class="badges">
          @foreach(array_slice($item['badges'], 0, 2) as $badge)
            <span class="badge">{{ $badge }}</span>
          @endforeach
        </div>
      @endif
      <div class="title">{{ $item['title'] }}</div>
    </div>
    <div class="rows">
      @foreach($rows as $index => $row)
        <div class="row {{ $index === 0 ? 'first' : '' }}">
          <div class="rank">{{ $index + 1 }}.</div>
          @if($row['image'])
            <div class="thumb"><img src="{{ $row['image'] }}" alt=""></div>
          @endif
          <div class="who">
            @include('templates.partials.provider', ['item' => ['provider' => $row['provider']], 'name' => true])
            @if($row['title'])
              <div class="product">{{ $row['title'] }}</div>
            @endif
          </div>
          <div class="figure">
            <div class="value">{{ $row['value'] }}</div>
            @if($row['price'])
              <div class="pack">{{ $row['price'] }}</div>
            @endif
          </div>
        </div>
      @endforeach
    </div>
    @if($item['expires_at'])
      <div class="valid">Cijene iz letaka, vrijede do {{ $item['expires_at'] }}</div>
    @endif
  </div>
  @include('templates.partials.footer', ['cta' => $item['cta_label'] ?? null])
@endsection
