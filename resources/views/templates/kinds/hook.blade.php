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
    // A deal's picture is a crop of the catalog page, neighbours' prices included: stretched behind
    // the text it turns into noise, so the product is shown whole on a card of its own.
    $product = ($item['kind'] ?? null) === 'deal' && ! empty($item['primary_image']);
    $gap = $product ? ($story ? 28 : 24) : ($story ? 44 : 30);
@endphp

@section('styles')
  .hook { position: relative; flex: 1; display: flex; flex-direction: column; justify-content: center;
          gap: {{ $gap }}px; padding: {{ $story ? '260px 84px 440px' : '64px 72px' }};
          color: #fff; overflow: hidden;
          background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .hook .bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
  .hook .shade { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,.30) 0%, rgba(0,0,0,.75) 100%); }
  .hook .layer { position: relative; display: flex; flex-direction: column; gap: {{ $gap }}px; }
  .hook .brandline { display: flex; align-items: center; gap: 18px; font-size: 34px; font-weight: 800; opacity: .95; }
  .hook .brandline img { height: 60px; width: auto; max-width: 260px; object-fit: contain; {!! $brand['logo_filter'] ?? '' !!} }
  .hook .product { height: {{ $story ? 440 : 360 }}px; background: #fff; border-radius: 36px; overflow: hidden;
                   display: flex; align-items: center; justify-content: center; box-shadow: 0 18px 50px rgba(0,0,0,.25); }
  .hook .product img { width: 100%; height: 100%; object-fit: contain; padding: 24px; }
  .hook .badges { position: static; max-width: 100%; }
  .hook .figure-label { font-size: {{ $story ? 34 : 28 }}px; font-weight: 800; letter-spacing: 4px; opacity: .88; }
  .hook .figure { font-size: {{ $story ? 150 : 122 }}px; font-weight: 900; line-height: 1; letter-spacing: -3px; }
  .hook .figure-old { margin-top: 14px; font-size: {{ $story ? 64 : 52 }}px; font-weight: 700; text-decoration: line-through; opacity: .8; }
  .hook .points { display: flex; flex-direction: column; gap: 12px; font-size: {{ $story ? 50 : 40 }}px; font-weight: 800; }
  .hook .title { font-size: {{ $story ? 76 : 62 }}px; -webkit-line-clamp: {{ $product ? 2 : 4 }}; }
  .hook .provider { align-self: flex-start; }
@endsection

@section('card')
  <div class="hook">
    @if($item['primary_image'] && ! $product)
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
      @if($product)
        <div class="product"><img src="{{ $item['primary_image'] }}" alt=""></div>
      @endif
      {{-- Whose offer it is, before the number: a price with no shop behind it reads as ours. --}}
      @include('templates.partials.provider', ['name' => true, 'class' => $story ? 'provider--lg' : ''])
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
          @if(!empty($hook['figure_old']))
            <div class="figure-old">{{ $hook['figure_old'] }}</div>
          @endif
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
      {{-- No subtitle line: it is the provider's name, and the plaque above already says it. --}}
    </div>
  </div>
  @unless($story)
    @include('templates.partials.footer', ['cta' => $item['cta_label'] ?? null])
  @endunless
@endsection
