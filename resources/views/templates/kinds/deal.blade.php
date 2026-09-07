@extends('templates._layout')

@php
    // The crop comes from a catalog page and often carries the retailer's own price graphics, so
    // the hub's own discount badge is shown once (the circle) and the chip list is suppressed.
    $hasDiscountCircle = ! empty($item['price']['discount_pct']);
@endphp

@section('styles')
  /* Product crops are the subject, not a backdrop: fit the whole thing rather than cropping it. */
  .hero { flex-basis: {{ (int) round($height * 0.42) }}px; background: #fff; }
  .hero img { object-fit: contain; padding: 18px; }
  .body { padding-top: 40px; gap: 16px; }
  .title { font-size: 58px; -webkit-line-clamp: 2; }
  .price .now { font-size: 92px; }
@endsection

@section('card')
  <div class="hero">
    @if($item['primary_image'])
      <img src="{{ $item['primary_image'] }}" alt="">
    @else
      <div class="placeholder"><span class="emoji">{{ $item['emoji'] }}</span></div>
    @endif
    @if(! $hasDiscountCircle && !empty($item['badges']))
      <div class="badges">
        @foreach(array_slice($item['badges'], 0, 3) as $badge)
          <span class="badge">{{ $badge }}</span>
        @endforeach
      </div>
    @endif
    @if($item['logo_image'])
      <div class="logo-chip"><img src="{{ $item['logo_image'] }}" alt=""></div>
    @endif
  </div>

  @if($hasDiscountCircle)
    <div class="discount">−{{ $item['price']['discount_pct'] }}%</div>
  @endif

  <div class="body">
    <div class="title">{{ $item['title'] }}</div>
    @if($item['subtitle'])
      <div class="subtitle">{{ $item['subtitle'] }}</div>
    @endif
    @if(!empty($item['price']) && isset($item['price']['current_cents']))
      <div class="price">
        <div class="now">{{ number_format($item['price']['current_cents'] / 100, 2, ',', '.') }} €</div>
        @if(!empty($item['price']['old_cents']))
          <div class="old">{{ number_format($item['price']['old_cents'] / 100, 2, ',', '.') }} €</div>
        @endif
        @if(!empty($item['price']['unit_label']))
          <div class="unit">{{ $item['price']['unit_label'] }}</div>
        @endif
      </div>
    @endif
    @if(!empty($item['facts']))
      <div class="facts">
        @foreach(array_slice($item['facts'], 0, 2) as $fact)
          <div class="fact">
            <div class="label">{{ $fact['label'] }}</div>
            <div class="value">{{ $fact['value'] }}</div>
          </div>
        @endforeach
      </div>
    @endif
    @if($item['expires_at'])
      <div class="valid">Vrijedi do {{ $item['expires_at'] }}</div>
    @endif
  </div>
  @include('templates.partials.footer', ['cta' => 'Dodaj na listu'])
@endsection
