@extends('templates._layout')

{{--
  First slide of a dish's video or carousel: the dish, and one line that makes a thumb stop. Which line is the brand's choice
  (`voice.recipe_hook`): the question people type into a search ("Što kuhati ovaj tjedan?"), the number of ingredients on sale, how
  quickly it is done, or "Iz letka na tanjur": the cover of the shop's leaflet and the dish it became, the one thing the apps that
  plan a week from the offers cannot show. Never the sum first: it is the sum of one pack of each ingredient on sale, and a dish with
  one dear ingredient makes it a number nobody would stay for. The shop's name is on the picture, the AI label on the dish.
  On 9:16 the top and bottom are covered by the app's interface: the content sits in the band between them.

  The question, the number and the time are one layout: the dish across the whole width, and a dark panel over its lower edge with the line
  in big type and the pills under it (time, portions, the day the offer ends). The leaflet hook is its own stage, below.
--}}
@php
    $serif = '"Noto Serif", "Liberation Serif", "DejaVu Serif", Georgia, serif';
    $r = $item['recipe'] ?? [];
    $leaflet = $item['recipe_leaflet'] ?? null;
    $story = $height > 1500;
    $square = $height <= 1080;
    $photo = $story ? 900 : ($square ? 560 : 720);
    $top = $story ? 190 : ($square ? 44 : 60);
    $gap = $story ? 96 : ($square ? 70 : 78);
    $style = $r['hook_style'] ?? 'question';
    // A leaflet that could not be fetched is no leaflet: the slide is then the question, as the brand's fallback.
    if ($style === 'leaflet' && $leaflet === null) {
        $style = 'question';
    }
    $line = match ($style) {
        'figure' => $r['hook_on_sale'] ?? '',
        'time' => $r['hook_time'] ?? '',
        'leaflet' => $r['hook_leaflet'] ?? '',
        default => $r['question'] ?? '',
    };
    $size = $story ? ($style === 'question' ? 128 : 116) : ($square ? 82 : 102);

    // The leaflet stage: the leaflet leans on the left, the dish lies over its corner on the right.
    $lh = $story ? 660 : ($square ? 400 : 500);
    $lw = (int) round($lh * ($leaflet['ratio'] ?? 0.72));
    $dish = $story ? 560 : ($square ? 360 : 460);
    $stage = $story ? 960 : ($square ? 470 : 640);
    $dishLeft = 1080 - ($story ? 70 : 56) - $dish;

    // The panel layout: where the panel starts, and the pills (the minutes are the line itself on the time hook).
    $panelTop = $story ? 960 : ($square ? 640 : 800);
    $labelTop = $story ? 200 : 40;
    $pills = array_values(array_filter($r['chips'] ?? [], fn (array $chip): bool => ! ($style === 'time' && ($chip['icon'] ?? '') === 'clock')));
@endphp

@section('styles')
@include('templates.partials.recipe-styles')
  .rh { position: relative; flex: 1; min-height: 0; overflow: hidden; display: flex; flex-direction: column; align-items: center; color: #fff;
        padding-top: {{ $top }}px; background: {{ $brand['primary'] }}; }
  .rh .rc-photo { width: {{ $photo }}px; height: {{ $photo }}px; }
  .rh__text { position: relative; width: 100%; padding: {{ $gap }}px {{ $story ? 80 : 64 }}px 0; display: flex; flex-direction: column; align-items: flex-start; gap: 26px; }
  .rh__line { font-family: {!! $serif !!}; font-weight: 700; font-size: {{ $size }}px; line-height: 1.04; letter-spacing: -2px; text-wrap: balance;
              text-shadow: 0 4px 30px rgba(0,0,0,.45); padding-bottom: .14em; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
  .rh__sub { font-size: {{ $story ? 46 : 36 }}px; font-weight: 800; opacity: .94; }
  .rh .rc-pill { font-size: {{ $story ? 56 : 44 }}px; padding: 12px 38px; }
  .rh .time-badge { position: absolute; z-index: 4; right: {{ $story ? 56 : 40 }}px; top: {{ $top - 70 }}px; }
  .rp { position: relative; flex: 1; min-height: 0; overflow: hidden; background: {{ $brand['primary'] }}; color: #fff; }
  .rp__photo { position: absolute; left: 0; top: 0; width: 100%; height: {{ $panelTop + 80 }}px; background: {{ $brand['surface'] }} center 40%/cover no-repeat; }
  .rp__shade { position: absolute; left: 0; top: 0; width: 100%; height: {{ $story ? 420 : 220 }}px; background: linear-gradient(180deg, rgba(0,0,0,.4), rgba(0,0,0,0)); }
  .rp .rp__ai { left: 40px; top: {{ $labelTop }}px; font-size: {{ $story ? 30 : 26 }}px; }
  .rp .rp__chain { right: 40px; left: auto; top: {{ $labelTop }}px; bottom: auto; font-size: {{ $story ? 48 : 40 }}px; }
  .rp__panel { position: absolute; left: 0; right: 0; top: {{ $panelTop }}px; bottom: 0; border-radius: 90px 90px 0 0; display: flex; flex-direction: column; align-items: flex-start;
               gap: {{ $story ? 34 : 24 }}px; padding: {{ $story ? '76px 140px 0 72px' : ($square ? '50px 72px 0' : '60px 72px 0') }};
               background: {{ $brand['primary'] }}; background: color-mix(in srgb, {{ $brand['primary'] }} 62%, #000); }
  .rp .rh__line { text-shadow: none; }
  .rp__chips { display: flex; flex-wrap: wrap; gap: 16px; }
  .rp__chip { display: inline-flex; align-items: center; gap: 12px; background: rgba(255,255,255,.15); color: #fff; font-weight: 800; white-space: nowrap;
              font-size: {{ $story ? 40 : 32 }}px; padding: {{ $story ? '16px 28px' : '12px 22px' }}; border-radius: 999px; }
  .rp__chip--sun { background: #ffd84d; color: #1f2a21; }
  .lf-stage { position: relative; width: 100%; height: {{ $stage }}px; flex: none; }
  .lf-leaflet { position: absolute; left: {{ $story ? 74 : 60 }}px; top: {{ $story ? 10 : 6 }}px; width: {{ $lw }}px; height: {{ $lh }}px; transform: rotate(-6deg);
                background: #fff center/cover no-repeat; border: 12px solid #fff; border-radius: 14px; box-shadow: 0 26px 70px rgba(0,0,0,.45); }
  .lf-leaflet .rc-plaque { left: -14px; right: auto; bottom: -34px; font-size: {{ $story ? 46 : 38 }}px; transform: rotate(6deg); }
  .lf-dish { position: absolute; left: {{ $dishLeft }}px; top: {{ $stage - $dish - ($story ? 20 : 10) }}px; width: {{ $dish }}px; height: {{ $dish }}px; transform: rotate(4deg);
             background-size: cover; background-position: center; border-radius: 52px; box-shadow: 0 30px 80px rgba(0,0,0,.5); border: 10px solid #fff; }
  .lf-dish .rc-ai { left: 18px; top: 18px; font-size: {{ $story ? 28 : 24 }}px; }
  .lf-arrow { position: absolute; left: {{ (int) round($lw * 0.78) }}px; top: {{ (int) round($stage * 0.36) }}px; z-index: 5; width: {{ $story ? 120 : 90 }}px; height: {{ $story ? 120 : 90 }}px;
              border-radius: 50%; background: #ffd84d; color: #1f2a21; display: flex; align-items: center; justify-content: center;
              font-size: {{ $story ? 84 : 62 }}px; font-weight: 900; box-shadow: 0 12px 34px rgba(0,0,0,.4); }
@endsection

@section('card')
@if($style === 'leaflet')
  <div class="rh">
    @if($item['primary_image'])
      <div class="rc-bg" style="background-image: url('{{ $item['primary_image'] }}')"></div>
    @endif
    <div class="lf-stage">
      <div class="lf-leaflet" style="background-image: url('{{ $leaflet['image'] }}')">
        @if(!empty($r['chain']))
          <div class="rc-plaque">{{ $r['chain'] }}</div>
        @endif
      </div>
      <div class="lf-arrow">→</div>
      <div class="lf-dish" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif>
        @if(!empty($r['ai']))
          <div class="rc-ai">{{ $r['ai_label'] }}</div>
        @endif
      </div>
    </div>
    <div class="rh__text">
      <div class="rh__line">{{ $line }}</div>
    </div>
  </div>
@else
  <div class="rp">
    <div class="rp__photo" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif></div>
    <div class="rp__shade"></div>
    @if(!empty($r['ai']))
      <div class="rc-ai rp__ai">{{ $r['ai_label'] }}</div>
    @endif
    @if(!empty($r['chain']))
      <div class="rc-plaque rp__chain">{{ $r['chain'] }}</div>
    @endif
    <div class="rp__panel">
      <div class="rh__line">{{ $line }}</div>
      @if($style === 'time')
        <div class="rh__sub">{{ $r['from_leaflet'] ?? '' }}</div>
      @endif
      <div class="rp__chips">
        @if($style === 'figure' && !empty($r['hook_saving']))
          <span class="rp__chip rp__chip--sun">{{ $r['hook_saving'] }}</span>
        @endif
        @foreach($pills as $pill)
          <span class="rp__chip{{ !empty($pill['accent']) ? ' rp__chip--sun' : '' }}">
            @include('templates.partials.recipe-icon', ['icon' => $pill['icon'], 'size' => $story ? 40 : 32, 'color' => !empty($pill['accent']) ? '#1f2a21' : '#fff'])
            {{ $pill['text'] }}
          </span>
        @endforeach
      </div>
    </div>
  </div>
@endif
@endsection
