@extends('templates._layout')

{{--
  The pin of a dish (app/Pinterest): the dish on the upper two thirds, a panel under it with the line, the brand and the site. Nothing on it ends:
  no price, no date, no count of ingredients on sale. A pin lives for months and the dish's offers end on Wednesday; the pin promises "recipes
  from the shop's offers", and the page it links to keeps that promise every week. The AI label of the picture is on the picture.
  The words are the pin's own (`$item['pin']`, config/pinterest.php); the label is RecipeCopy's.
--}}
@php
    $serif = '"Noto Serif", "Liberation Serif", "DejaVu Serif", Georgia, serif';
    $pin = $item['pin'] ?? [];
    $r = $item['recipe'] ?? [];
    $photo = 960;
@endphp

@section('styles')
@include('templates.partials.recipe-styles')
  .pn { position: relative; flex: 1; min-height: 0; overflow: hidden; background: {{ $brand['primary'] }}; color: #fff; }
  .pn__photo { position: absolute; left: 0; top: 0; width: 100%; height: {{ $photo }}px; background: {{ $brand['surface'] }} center 40%/cover no-repeat; }
  .pn__panel { position: absolute; left: 0; right: 0; top: {{ $photo }}px; bottom: 0; padding: 52px 64px 0; display: flex; flex-direction: column; gap: 18px; }
  .pn__eyebrow { font-size: 30px; font-weight: 700; opacity: .88; }
  .pn__line { font-family: {!! $serif !!}; font-weight: 700; font-size: 70px; line-height: 1.06; letter-spacing: -1.5px; text-wrap: balance; padding-bottom: .12em;
              overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
  .pn__foot { position: absolute; left: 64px; right: 64px; bottom: 44px; display: flex; align-items: center; justify-content: space-between; gap: 24px; }
  .pn__brand { display: flex; align-items: center; gap: 16px; min-width: 0; }
  .pn__brand img { height: 58px; width: auto; max-width: 240px; object-fit: contain; {!! $brand['logo_filter'] ?? '' !!} }
  .pn__brand b { font-family: {!! $serif !!}; font-size: 42px; }
  .pn__site { font-size: 30px; font-weight: 700; opacity: .88; white-space: nowrap; }
@endsection

@section('card')
<div class="pn">
  <div class="pn__photo" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif></div>
  @if(($r['ai'] ?? false) && filled($r['ai_label'] ?? null))
    <span class="rc-ai">{{ $r['ai_label'] }}</span>
  @endif
  <div class="pn__panel">
    <div class="pn__eyebrow">{{ $pin['eyebrow'] ?? '' }}</div>
    <div class="pn__line">{{ $pin['headline'] ?? '' }}</div>
    <div class="pn__foot">
      <div class="pn__brand">
        @if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="">@endif
        <b>{{ $brand['name'] }}</b>
      </div>
      <span class="pn__site">{{ $brand['site'] }}</span>
    </div>
  </div>
</div>
@endsection
