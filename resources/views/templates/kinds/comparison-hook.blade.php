@extends('templates._layout')

{{--
  First slide of a comparison (Social Feed v1, kind=comparison): the question, then who answers it.
  The cheapest shop gets a card of its own — its mark and name as the headline, the figure under it,
  the product as the proof — and the next two follow as tiles with their own marks, so the ranking
  reads from the logos before a single price is read. A shop named only in a line of text is what
  made the old hook look like a price list. On 9:16 the app covers the top and the bottom, so
  everything sits in the middle band and the footer bar is left out.
--}}
@php
    $story = $height > 1500;
    $square = $height <= 1080;
    // One size per orientation: 9:16, 4:5, 1:1.
    $px = fn (int $story_, int $portrait, int $square_): int => $story ? $story_ : ($square ? $square_ : $portrait);
    $rows = $item['rows'] ?? [];
    $first = $rows[0] ?? null;
    $next = array_slice($rows, 1, 2);
    // The feed's first picture is the first row's product whole; the row's own is a thumbnail.
    $shot = $item['primary_image'] ?? ($first['image'] ?? null);
    $proof = implode(' · ', array_filter([$first['title'] ?? null, $first['price'] ?? null]));
@endphp

@section('styles')
  .ch { position: relative; flex: 1; min-height: 0; display: flex; flex-direction: column; justify-content: center;
        gap: {{ $px(34, 28, 20) }}px; padding: {{ $story ? '250px 64px 440px' : ($square ? '36px 52px' : '56px 60px') }};
        color: #fff; overflow: hidden;
        background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .ch .brandline { display: flex; align-items: center; gap: 18px; font-size: 34px; font-weight: 800; opacity: .95; }
  .ch .brandline img { height: 60px; width: auto; max-width: 260px; object-fit: contain; {!! $brand['logo_filter'] ?? '' !!} }
  .ch .title { font-size: {{ $px(74, 60, 50) }}px; -webkit-line-clamp: 3; }

  /* The cheapest shop. */
  .winner { position: relative; background: #fff; color: {{ $brand['text'] }}; border-radius: {{ $px(40, 36, 30) }}px;
            padding: {{ $px(40, 34, 26) }}px; display: flex; flex-direction: column; gap: {{ $px(18, 14, 8) }}px;
            box-shadow: 0 24px 60px rgba(0,0,0,.28); }
  .winner .top { display: flex; align-items: center; gap: 24px; }
  .winner .who { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: {{ $px(22, 18, 12) }}px; }
  .winner .ribbon { background: #ffd23f; color: #1f1a00; font-size: {{ $px(30, 26, 22) }}px; font-weight: 900; letter-spacing: 2px;
                    padding: 10px 22px; border-radius: 999px; line-height: 1.1; }
  .winner .provider { background: transparent; box-shadow: none; padding: 0; border-radius: 0; max-width: 100%; gap: {{ $px(24, 20, 16) }}px; }
  .winner .provider img { height: {{ $px(96, 84, 64) }}px; max-width: 420px; }
  .winner .provider--compact img { height: {{ $px(124, 108, 84) }}px; max-width: {{ $px(124, 108, 84) }}px; border-radius: 22px; }
  .winner .provider .name { font-size: {{ $px(76, 66, 54) }}px; font-weight: 900; letter-spacing: -1px; }
  .winner .shot { flex: 0 0 {{ $px(250, 210, 160) }}px; height: {{ $px(250, 210, 160) }}px; border-radius: 22px; overflow: hidden; background: #fff; }
  .winner .shot img { width: 100%; height: 100%; object-fit: contain; display: block; }
  .winner .figure { font-size: {{ $px(132, 112, 88) }}px; font-weight: 900; line-height: 1; letter-spacing: -3px; color: {{ $brand['primary'] }}; white-space: nowrap; }
  .winner .proof { font-size: {{ $px(32, 28, 24) }}px; font-weight: 600; color: {{ $brand['muted'] }}; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

  /* Second and third, with their own marks. */
  .next { display: flex; gap: {{ $px(24, 20, 16) }}px; }
  .tile { flex: 1; min-width: 0; background: rgba(255,255,255,.94); color: {{ $brand['text'] }}; border-radius: {{ $px(30, 26, 22) }}px;
          padding: {{ $px(26, 22, 16) }}px {{ $px(28, 24, 20) }}px; display: flex; flex-direction: column; gap: {{ $px(14, 12, 8) }}px;
          box-shadow: 0 12px 30px rgba(0,0,0,.16); }
  .tile .head { display: flex; align-items: center; gap: 12px; min-width: 0; }
  .tile .rank { flex: 0 0 auto; font-size: {{ $px(38, 34, 28) }}px; font-weight: 900; color: {{ $brand['muted'] }}; }
  .tile .provider { background: transparent; box-shadow: none; padding: 0; border-radius: 0; min-width: 0; max-width: 100%; gap: 14px; }
  .tile .provider img { height: {{ $px(56, 50, 40) }}px; max-width: 260px; }
  .tile .provider--compact img { height: {{ $px(76, 66, 52) }}px; max-width: {{ $px(76, 66, 52) }}px; border-radius: 14px; }
  .tile .provider .name { font-size: {{ $px(42, 38, 32) }}px; font-weight: 800; }
  .tile .value { font-size: {{ $px(58, 50, 42) }}px; font-weight: 900; letter-spacing: -1px; line-height: 1; white-space: nowrap; }

  .ch .badges { position: static; max-width: 100%; }
  .ch .badge { background: rgba(255,255,255,.18); color: #fff; font-size: {{ $px(30, 26, 22) }}px; }
@endsection

@section('card')
  <div class="ch">
    @if($story)
      <div class="brandline">
        @if($brand['logo'])
          <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">
        @endif
        <span>{{ $brand['site'] ?? $brand['name'] }}</span>
      </div>
    @endif

    <div class="title">{{ $item['title'] }}</div>

    @if($first)
      <div class="winner">
        <div class="top">
          <div class="who">
            <div class="ribbon">NAJJEFTINIJE</div>
            @include('templates.partials.provider', ['item' => ['provider' => $first['provider']], 'name' => true])
          </div>
          @if($shot)
            <div class="shot"><img src="{{ $shot }}" alt=""></div>
          @endif
        </div>
        <div class="figure">{{ $first['value'] }}</div>
        @if($proof !== '')
          <div class="proof">{{ $proof }}</div>
        @endif
      </div>
    @endif

    @if($next !== [])
      <div class="next">
        @foreach($next as $index => $row)
          <div class="tile">
            <div class="head">
              <div class="rank">{{ $index + 2 }}.</div>
              @include('templates.partials.provider', ['item' => ['provider' => $row['provider']], 'name' => true])
            </div>
            <div class="value">{{ $row['value'] }}</div>
          </div>
        @endforeach
      </div>
    @endif

    @if(!empty($item['badges']))
      <div class="badges">
        @foreach(array_slice($item['badges'], 0, 2) as $badge)
          <span class="badge">{{ $badge }}</span>
        @endforeach
      </div>
    @endif
  </div>
  @unless($story)
    @include('templates.partials.footer', ['cta' => $item['cta_label'] ?? null])
  @endunless
@endsection
