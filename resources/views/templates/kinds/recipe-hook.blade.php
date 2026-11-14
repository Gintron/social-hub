@extends('templates._layout')

{{--
  First slide of a dish's video or carousel: the dish, and one line that makes a thumb stop. Which line is the brand's choice
  (`voice.recipe_hook`): the question people type into a search ("Što kuhati ovaj tjedan?"), the number of ingredients on sale, how
  quickly it is done, or "Iz letka na tanjur": the cover of the shop's leaflet and the dish it became, the one thing the apps that
  plan a week from the offers cannot show. Never the sum first: it is the sum of one pack of each ingredient on sale, and a dish with
  one dear ingredient makes it a number nobody would stay for. The shop's name is on the picture, the AI label on the dish.
  On 9:16 the top and bottom are covered by the app's interface: the content sits in the band between them.
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
    $size = $story ? ($style === 'question' ? 128 : 116) : ($square ? 78 : 96);

    // The leaflet stage: the leaflet leans on the left, the dish lies over its corner on the right.
    $lh = $story ? 660 : ($square ? 400 : 500);
    $lw = (int) round($lh * ($leaflet['ratio'] ?? 0.72));
    $dish = $story ? 560 : ($square ? 360 : 460);
    $stage = $story ? 960 : ($square ? 470 : 640);
    $dishLeft = 1080 - ($story ? 70 : 56) - $dish;
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
  <div class="rh">
    @if($item['primary_image'])
      <div class="rc-bg" style="background-image: url('{{ $item['primary_image'] }}')"></div>
    @endif
    @if($style === 'leaflet')
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
    @else
      <div class="rc-photo" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif>
        @if(!empty($r['ai']))
          <div class="rc-ai">{{ $r['ai_label'] }}</div>
        @endif
        @if(!empty($r['chain']))
          <div class="rc-plaque">{{ $r['chain'] }}</div>
        @endif
      </div>
    @endif
    <div class="rh__text">
      <div class="rh__line">{{ $line }}</div>
      @if($style === 'figure' && !empty($r['hook_saving']))
        <div class="rc-pill">{{ $r['hook_saving'] }}</div>
      @elseif($style === 'time')
        <div class="rh__sub">{{ $r['from_leaflet'] ?? '' }}</div>
      @endif
    </div>
  </div>
@endsection
