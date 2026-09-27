@extends('templates._layout')

@php
    $story = $height > 1500;
    $dark = ($item['theme'] ?? '') === 'dark';
@endphp

@section('styles')
  .feature { flex: 1; min-height: 0; padding: {{ $story ? '230px 150px 370px 76px' : '70px 76px' }};
    display: flex; flex-direction: column; gap: 30px;
    background: {{ $dark ? $brand['primary'] : $brand['background'] }};
    color: {{ $dark ? '#fffdf8' : $brand['text'] }}; }
  .identity { display: flex; align-items: center; gap: 16px; font-size: 30px; font-weight: 800; }
  .identity img { width: 50px; height: 50px; object-fit: contain; border-radius: 12px; }
  .feature .eyebrow { font-size: 28px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; opacity: .8; }
  .feature .headline { font-size: {{ $story ? 84 : 68 }}px; line-height: 1.06; font-weight: 900; letter-spacing: -2px; }
  .feature .detail { font-size: 38px; font-weight: 600; line-height: 1.25; }
  .feature .screen { position: relative; flex: 1; min-height: 0; border: 8px solid {{ $dark ? '#7aad86' : '#d9e2d5' }};
    border-radius: 38px; overflow: hidden; background: #f4f1e9; box-shadow: 0 18px 36px #0002; }
  .feature .screen img { width: 100%; height: 100%; object-fit: cover; object-position: center {{ (int) ($item['image_position'] ?? 50) }}%; display: block; }
  .feature .note { font-size: 27px; line-height: 1.25; opacity: .8; }
@endsection

@section('card')
  <div class="feature">
    <div class="identity">
      @if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="">@endif
      <span>{{ $brand['name'] }} · {{ $item['label'] ?? 'Kako radi' }}</span>
    </div>
    <div class="headline">{{ $item['title'] }}</div>
    @if(!empty($item['excerpt']))<div class="detail">{{ $item['excerpt'] }}</div>@endif
    @if(!empty($item['primary_image']))
      <div class="screen"><img src="{{ $item['primary_image'] }}" alt="Prikaz aplikacije"></div>
    @endif
    @if(!empty($item['note']))<div class="note">{{ $item['note'] }}</div>@endif
  </div>
@endsection
