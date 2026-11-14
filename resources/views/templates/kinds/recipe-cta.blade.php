@extends('templates._layout')

{{--
  Last slide of a dish: where the recipe and the shopping list are, and where the link is. The words are RecipeCopy's (the same the
  voice says), so a channel that says "u opisu" gets its own file (`$item['recipe']['link_sentence']`). The picture is shown small
  with its label: this slide is also what a carousel ends on.
--}}
@php
    $serif = '"Noto Serif", "Liberation Serif", "DejaVu Serif", Georgia, serif';
    $r = $item['recipe'] ?? [];
    $story = $height > 1500;
    $square = $height <= 1080;
    $photo = $story ? 440 : ($square ? 260 : 360);
@endphp

@section('styles')
@include('templates.partials.recipe-styles')
  .re { flex: 1; min-height: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; color: #fff;
        gap: {{ $story ? 44 : 32 }}px; padding: {{ $story ? '230px 80px 440px' : '60px 80px' }};
        background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .re .rc-photo { width: {{ $photo }}px; height: {{ $photo }}px; border-radius: 50%; box-shadow: 0 20px 60px rgba(0,0,0,.35); }
  .re .rc-ai { left: 50%; transform: translateX(-50%); top: auto; bottom: -22px; white-space: nowrap; font-size: 26px; }
  .re__line { font-family: {!! $serif !!}; font-weight: 700; font-size: {{ $story ? 82 : ($square ? 54 : 66) }}px; line-height: 1.08; letter-spacing: -1px; text-wrap: balance; max-width: 920px; }
  .re__button { background: #fff; color: {{ $brand['primary'] }}; font-weight: 800; font-size: {{ $story ? 62 : 48 }}px; padding: 26px 70px; border-radius: 999px; box-shadow: 0 14px 40px rgba(0,0,0,.22); }
  .re__where { font-size: {{ $story ? 48 : 38 }}px; font-weight: 800; max-width: 900px; line-height: 1.2; }
  .re__ai { font-size: {{ $story ? 32 : 26 }}px; font-weight: 500; opacity: .85; }
@endsection

@section('card')
  <div class="re">
    <div class="rc-photo" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif>
      @if(!empty($r['ai']))
        <div class="rc-ai">{{ $r['ai_label'] }}</div>
      @endif
    </div>
    <div class="re__line">{{ $r['cta_line'] ?? '' }}</div>
    <div class="re__button">{{ $r['cta_get'] ?? ($brand['cta'] ?? $brand['name']) }}</div>
    <div class="re__where">{{ $r['link_sentence'] ?? '' }}</div>
    @if(!empty($r['ai']))
      <div class="re__ai">{{ $r['ai_note'] ?? '' }}</div>
    @endif
  </div>
@endsection
