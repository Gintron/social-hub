@extends('templates._layout')

@php
    // The crop comes from a catalog page and often carries the retailer's own price graphics, so
    // the hub's own discount badge is shown once (the circle) and the chip list is suppressed.
    $hasDiscountCircle = ! empty($item['price']['discount_pct']);
    // Whose shelf this price is from. On a deal it is the whole point: without it the card reads as
    // if the brand were selling the product, so the chain gets a band of its own under the picture
    // and the hero gives up the height for it.
    $provider = $item['provider'] ?? [];
    $hasProvider = ! empty($provider['logo']) || ! empty($provider['name']);
@endphp

@section('styles')
  /* Product crops are the subject, not a backdrop: fit the whole thing rather than cropping it. */
  .hero { flex-basis: {{ (int) round($height * ($hasProvider ? 0.36 : 0.42)) }}px; background: #fff; }
  .hero img { object-fit: contain; padding: 18px; }
  .chain { flex: 0 0 auto; display: flex; align-items: center; gap: 30px; padding: 24px 64px; background: {{ $brand['surface'] }}; }
  .chain .label { font-size: 26px; font-weight: 800; letter-spacing: 3px; line-height: 1.25; max-width: 200px; color: {{ $brand['muted'] }}; }
  .body { padding-top: 36px; gap: 16px; }
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
  </div>

  @if($hasDiscountCircle)
    <div class="discount">−{{ $item['price']['discount_pct'] }}%</div>
  @endif

  @if($hasProvider)
    {{-- The label ends on "trgovini" so the chain's own name never has to be declined into it. --}}
    <div class="chain">
      <div class="label">AKCIJA U TRGOVINI</div>
      @include('templates.partials.provider', ['name' => true, 'class' => 'provider--lg'])
    </div>
  @endif

  <div class="body">
    <div class="title">{{ $item['title'] }}</div>
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
