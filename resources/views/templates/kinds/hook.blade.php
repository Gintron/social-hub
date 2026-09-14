@extends('templates._layout')

{{--
  First slide of a video or carousel: the number and the place, large enough to read while the
  thumb is still scrolling. On 9:16 the top and bottom are covered by the app's own interface, so
  everything sits in the middle band and the footer bar is left out.
--}}
@php
    $story = $height > 1500;
    $hook = $item['hook'] ?? [];
    $figure = $hook['figure'] ?? null;
@endphp

@section('styles')
  .hook { position: relative; flex: 1; display: flex; flex-direction: column; justify-content: center;
          gap: {{ $story ? 44 : 30 }}px; padding: {{ $story ? '260px 84px 440px' : '64px 72px' }};
          color: #fff; overflow: hidden;
          background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .hook .bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .hook .shade { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,.30) 0%, rgba(0,0,0,.75) 100%); }
  .hook .layer { position: relative; display: flex; flex-direction: column; gap: {{ $story ? 44 : 30 }}px; }
  .hook .brandline { display: flex; align-items: center; gap: 18px; font-size: 34px; font-weight: 800; opacity: .95; }
  .hook .brandline img { height: 60px; width: auto; max-width: 260px; object-fit: contain; filter: brightness(0) invert(1); }
  .hook .badges { position: static; max-width: 100%; }
  .hook .figure-label { font-size: {{ $story ? 34 : 28 }}px; font-weight: 800; letter-spacing: 4px; opacity: .88; }
  .hook .figure { font-size: {{ $story ? 150 : 122 }}px; font-weight: 900; line-height: 1; letter-spacing: -3px; }
  .hook .points { display: flex; flex-direction: column; gap: 12px; font-size: {{ $story ? 50 : 40 }}px; font-weight: 800; }
  .hook .title { font-size: {{ $story ? 76 : 62 }}px; -webkit-line-clamp: 4; }
  .hook .subtitle { color: rgba(255,255,255,.88); font-size: {{ $story ? 40 : 34 }}px; }
@endsection

@section('card')
  <div class="hook">
    @if($item['primary_image'])
      <img class="bg" src="{{ $item['primary_image'] }}" alt="">
      <div class="shade"></div>
    @endif
    <div class="layer">
      @if($story)
        <div class="brandline">
          @if($brand['logo'])
            <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">
          @endif
          <span>{{ $brand['site'] ?? $brand['name'] }}</span>
        </div>
      @endif
      @if(!empty($item['badges']))
        <div class="badges">
          @foreach(array_slice($item['badges'], 0, 3) as $badge)
            <span class="badge">{{ $badge }}</span>
          @endforeach
        </div>
      @endif
      @if($figure)
        <div>
          @if(!empty($hook['figure_label']))
            <div class="figure-label">{{ $hook['figure_label'] }}</div>
          @endif
          <div class="figure">{{ $figure }}</div>
        </div>
      @endif
      @if(!empty($hook['points']))
        <div class="points">
          @foreach($hook['points'] as $point)
            <div>{{ $point }}</div>
          @endforeach
        </div>
      @endif
      <div class="title"><span class="emoji">{{ $item['emoji'] }}</span> {{ $item['title'] }}</div>
      @if($item['subtitle'])
        <div class="subtitle">{{ $item['subtitle'] }}</div>
      @endif
    </div>
  </div>
  @unless($story)
    @include('templates.partials.footer', ['cta' => $item['cta_label'] ?? null])
  @endunless
@endsection
