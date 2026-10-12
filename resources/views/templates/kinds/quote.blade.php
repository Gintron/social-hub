@extends('templates._layout')

{{--
  A quote: the words, and whose they are. `title` is the text as the source wrote it, `subtitle` the
  attribution (a person, a book and verse). Nothing here knows what the text is or what language it
  is in; the brand brings its colours and logo, the source brings the words.

  The size follows the length, in steps, because a one-line saying and a three-sentence passage have
  to fill the same card without either being a wall of small type or shouting. The longest the
  contract allows (300 characters) is the smallest step and still fits the story frame.
--}}
@php
  $story = $height > 1500;
  $length = mb_strlen((string) $item['title']);
  $scale = $story ? 1.0 : ($height > 1200 ? 0.9 : 0.78);
  $base = match (true) {
    $length <= 70 => 96,
    $length <= 130 => 80,
    $length <= 200 => 68,
    $length <= 260 => 60,
    default => 54,
  };
  $size = (int) round($base * $scale);
  $serif = '"Noto Serif", "Liberation Serif", "DejaVu Serif", Georgia, serif';
@endphp

@section('styles')
  .card { background: {{ $brand['primary'] }}; color: #fff; }
  .quote { flex: 1; min-height: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
           text-align: center; padding: {{ $story ? '200px 90px 40px' : '60px 84px 40px' }}; gap: {{ $story ? 44 : 32 }}px;
           background: radial-gradient(120% 80% at 50% 0%, rgba(255,255,255,.14) 0%, rgba(255,255,255,0) 60%), {{ $brand['primary'] }}; }
  .quote .mark { font-family: {!! $serif !!}; font-size: {{ (int) round(220 * $scale) }}px;
                 line-height: .6; height: {{ (int) round(110 * $scale) }}px; color: {{ $brand['accent'] }}; font-weight: 700; }
  .quote .text { font-family: {!! $serif !!}; font-weight: 600;
                 font-size: {{ $size }}px; line-height: 1.22; letter-spacing: -0.3px; white-space: pre-line;
                 text-wrap: balance; overflow-wrap: break-word; max-width: 100%; }
  .quote .rule { width: 120px; height: 6px; border-radius: 3px; background: {{ $brand['accent'] }}; }
  /* Left as it was written: a reference is "Iv 3,16", and in capitals it would read as a different book. */
  .quote .source { font-size: {{ (int) round(50 * $scale) }}px; font-weight: 800; letter-spacing: 1px;
                   color: {{ $brand['accent'] }}; max-width: 100%; }
@endsection

@section('card')
  <div class="quote">
    <div class="mark">“</div>
    <div class="text">{{ $item['title'] }}</div>
    @if(filled($item['subtitle']))
      <div class="rule"></div>
      <div class="source">{{ $item['subtitle'] }}</div>
    @endif
  </div>
  @include('templates.partials.footer', ['cta' => ''])
@endsection
