@extends('templates._layout')

{{--
  A dish: its picture with its name over it, and the three numbers the post is about as three rows (ingredients on sale, what they come to,
  what the leaflet saves), then the pills (time, portions, the day the offer ends). No ingredient shows a product, a brand or a price: those
  belong to people with access, and a post is public. The main ingredients are on the last slide, as the list in the app.
  The footer is the brand's; on 9:16 its colour runs on under the app's interface, so everything sits above y = 1380. The label of the
  picture and the shop's plaque sit under the top of the app (9:16), not at the very edge.
--}}
@php
    $r = $item['recipe'] ?? [];
    $story = $height > 1500;
    $square = $height <= 1080;
    $photoHeight = $story ? 780 : ($square ? 470 : 700);
    $labelTop = $story ? 200 : 36;
    // A name is never cut with an ellipsis: the longer it is, the smaller it is set (German compounds are long: "Thunfisch mit warmem
    // Tomaten-Gemüse" does not fit two lines at the size of "Trofie s kozicama"), and a very long one gets a third line.
    $baseSize = $story ? 84 : ($square ? 58 : 72);
    $length = mb_strlen((string) $item['title']);
    $scale = match (true) {
        $length <= 26 => 1.0,
        $length <= 38 => 0.84,
        $length <= 52 => 0.72,
        default => 0.62,
    };
    $titleSize = (int) round($baseSize * $scale);
    $titleLines = $length > 52 ? 3 : 2;
    $pad = $story ? 64 : 56;
    $valueSize = $story ? 88 : ($square ? 54 : 72);
@endphp

@section('styles')
@include('templates.partials.recipe-styles')
  .rd { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; background: {{ $brand['background'] }}; }
  .rd__photo { position: relative; flex: 0 0 {{ $photoHeight }}px; background: {{ $brand['surface'] }} center/cover no-repeat; border-radius: 0 0 {{ $square ? 56 : 80 }}px {{ $square ? 56 : 80 }}px; overflow: hidden; }
  .rd__photo::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 62%; background: linear-gradient(180deg, rgba(10,18,12,0), rgba(10,18,12,.84)); }
  .rd__photo::before { content: ""; position: absolute; left: 0; right: 0; top: 0; height: {{ $story ? 380 : 180 }}px; background: linear-gradient(180deg, rgba(0,0,0,.4), rgba(0,0,0,0)); z-index: 1; }
  .rd .rd__ai { left: 40px; top: {{ $labelTop }}px; font-size: {{ $story ? 30 : 26 }}px; }
  .rd .rd__chain { right: 40px; left: auto; top: {{ $labelTop }}px; bottom: auto; font-size: {{ $story ? 48 : ($square ? 36 : 42) }}px; }
  .rd__name { position: absolute; z-index: 2; left: {{ $pad }}px; right: {{ $pad }}px; bottom: {{ $square ? 26 : 38 }}px; }
  .rd .rc-title { font-size: {{ $titleSize }}px; -webkit-line-clamp: {{ $titleLines }}; color: {{ $brand['text'] }}; hyphens: auto; }
  .rd__name .rc-title { color: #fff; text-shadow: 0 3px 24px rgba(0,0,0,.5); }
  .rd__body { flex: 1; min-height: 0; padding: {{ $story ? 30 : ($square ? 14 : 24) }}px {{ $pad }}px 0; display: flex; flex-direction: column; }
  .rd__row { display: flex; justify-content: space-between; align-items: baseline; gap: 24px; padding: {{ $square ? '10px' : ($story ? '14px' : '12px') }} 0; border-bottom: 3px solid rgba(0,0,0,.09); }
  .rd__label { font-size: {{ $story ? 30 : ($square ? 24 : 27) }}px; font-weight: 800; letter-spacing: 3px; color: {{ $brand['muted'] }}; text-transform: uppercase; min-width: 0; }
  .rd__value { font-family: "Noto Serif", "Liberation Serif", "DejaVu Serif", Georgia, serif; font-weight: 700; font-size: {{ $valueSize }}px; letter-spacing: -2px; line-height: 1.05; white-space: nowrap; }
  .rd__row--accent .rd__value { color: {{ $brand['primary'] }}; }
  .rd__chips { display: flex; flex-wrap: wrap; gap: 14px; margin-top: {{ $story ? 34 : ($square ? 18 : 26) }}px; }
  .rd__chip { display: inline-flex; align-items: center; gap: 12px; background: rgba(0,0,0,.06); color: {{ $brand['primary'] }}; font-weight: 800; white-space: nowrap;
              font-size: {{ $story ? 38 : ($square ? 28 : 32) }}px; padding: {{ $square ? '9px 20px' : '14px 26px' }}; border-radius: 999px; }
  .rd__chip--sun { background: #ffd84d; color: #1f2a21; }
@endsection

@section('card')
  <div class="rd">
    <div class="rd__photo" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif>
      @if(!empty($r['ai']))
        <div class="rc-ai rd__ai">{{ $r['ai_label'] }}</div>
      @endif
      @if(!empty($r['chain']))
        <div class="rc-plaque rd__chain">{{ $r['chain'] }}</div>
      @endif
      <div class="rd__name"><div class="rc-title">{{ $item['title'] }}</div></div>
    </div>
    <div class="rd__body">
      @foreach(($r['tiles'] ?? []) as $tile)
        <div class="rd__row{{ !empty($tile['accent']) ? ' rd__row--accent' : '' }}">
          <div class="rd__label">{{ $tile['label_long'] ?? $tile['label'] }}</div>
          <div class="rd__value">{{ $tile['value'] }}</div>
        </div>
      @endforeach
      <div class="rd__chips">
        @foreach(($r['chips'] ?? []) as $chip)
          <span class="rd__chip{{ !empty($chip['accent']) ? ' rd__chip--sun' : '' }}">
            @include('templates.partials.recipe-icon', ['icon' => $chip['icon'], 'size' => $story ? 38 : ($square ? 28 : 32), 'color' => !empty($chip['accent']) ? '#1f2a21' : $brand['primary']])
            {{ $chip['text'] }}
          </span>
        @endforeach
      </div>
    </div>
  </div>
  @include('templates.partials.footer', ['cta' => $r['footer'] ?? ''])
@endsection
