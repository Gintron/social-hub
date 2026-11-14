@extends('templates._layout')

{{--
  First slide of a dish's video or carousel: the dish, and one line that makes a thumb stop. Which line is the brand's choice
  (`voice.recipe_hook`): the question people type into a search ("Što kuhati ovaj tjedan?"), the number of ingredients on sale,
  or how quickly it is done. Never the sum first: it is the sum of one pack of each ingredient on sale, and a dish with one dear
  ingredient makes it a number nobody would stay for. The shop's name is on the picture, the AI label too.
  On 9:16 the top and bottom are covered by the app's interface: the content sits in the band between them.
--}}
@php
    $serif = '"Noto Serif", "Liberation Serif", "DejaVu Serif", Georgia, serif';
    $r = $item['recipe'] ?? [];
    $story = $height > 1500;
    $square = $height <= 1080;
    $photo = $story ? 900 : ($square ? 560 : 720);
    $top = $story ? 190 : ($square ? 44 : 60);
    $gap = $story ? 96 : ($square ? 70 : 78);
    $style = $r['hook_style'] ?? 'question';
    $line = match ($style) {
        'figure' => $r['hook_on_sale'] ?? '',
        'time' => $r['hook_time'] ?? '',
        default => $r['question'] ?? '',
    };
    $size = $story ? ($style === 'question' ? 128 : 116) : ($square ? 78 : 96);
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
@endsection

@section('card')
  <div class="rh">
    @if($item['primary_image'])
      <div class="rc-bg" style="background-image: url('{{ $item['primary_image'] }}')"></div>
    @endif
    <div class="rc-photo" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif>
      @if(!empty($r['ai']))
        <div class="rc-ai">{{ $r['ai_label'] }}</div>
      @endif
      @if(!empty($r['chain']))
        <div class="rc-plaque">{{ $r['chain'] }}</div>
      @endif
    </div>
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
