@extends('templates._layout')

{{--
  Last slide of a dish: where the recipe and the shopping list are, and where the link is. The words are RecipeCopy's (the same the
  voice says), so a channel that says "u opisu" gets its own file (`$item['recipe']['link_sentence']`). It is also what a carousel ends on.

  When the brand shows the main ingredients (`voice.recipe_highlights`, and the source sent them) the slide is the list in the app: a phone
  with the dish's ingredients ticked, which is the one thing the post can show that is the product itself. The names are the dish's, never an
  offer's: no product, brand or price. Without ingredients it is the dish in a circle, as it was. The picture carries its label either way
  (the note under the button), also when it is only the small round picture in the list.
--}}
@php
    $serif = '"Noto Serif", "Liberation Serif", "DejaVu Serif", Georgia, serif';
    $r = $item['recipe'] ?? [];
    $story = $height > 1500;
    $square = $height <= 1080;
    $photo = $story ? 440 : ($square ? 260 : 360);
    $rows = array_slice($r['highlights'] ?? [], 0, 4);
    $phoneWidth = $story ? 760 : ($square ? 540 : 640);
    $rowFont = $story ? 42 : ($square ? 30 : 36);
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
  .rz { width: {{ $phoneWidth }}px; border-radius: {{ $story ? 84 : 64 }}px; background: #101713; padding: {{ $story ? 20 : 14 }}px; box-shadow: 0 36px 80px rgba(0,0,0,.45); text-align: left; }
  .rz__screen { border-radius: {{ $story ? 66 : 50 }}px; background: {{ $brand['surface'] }}; color: {{ $brand['text'] }}; overflow: hidden; }
  .rz__head { background: {{ $brand['background'] }}; padding: {{ $story ? '48px 44px 24px' : ($square ? '30px 30px 16px' : '38px 36px 20px') }}; }
  .rz__kick { font-size: {{ $story ? 26 : 20 }}px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; color: {{ $brand['muted'] }}; }
  .rz__name { margin-top: 8px; font-family: {!! $serif !!}; font-weight: 700; font-size: {{ $story ? 46 : ($square ? 30 : 38) }}px; line-height: 1.06; letter-spacing: -1px;
              overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; padding-bottom: .12em; }
  .rz__rows { padding: {{ $story ? '8px 44px' : ($square ? '4px 30px' : '6px 36px') }}; }
  .rz__row { display: flex; align-items: center; gap: {{ $story ? 22 : 16 }}px; padding: {{ $story ? '26px 0' : ($square ? '14px 0' : '19px 0') }}; border-bottom: 2px solid rgba(0,0,0,.07); }
  .rz__row:last-child { border-bottom: 0; }
  .rz__box { flex: none; width: {{ $story ? 54 : ($square ? 38 : 46) }}px; height: {{ $story ? 54 : ($square ? 38 : 46) }}px; border-radius: 14px; background: {{ $brand['primary'] }}; display: flex; align-items: center; justify-content: center; }
  .rz__item { flex: 1; min-width: 0; font-size: {{ $rowFont }}px; font-weight: 700; line-height: 1.12; }
  .rz__tag { flex: none; font-size: {{ $story ? 24 : ($square ? 18 : 21) }}px; font-weight: 800; color: {{ $brand['primary'] }}; background: rgba(0,0,0,.06); padding: 8px 16px; border-radius: 999px; white-space: nowrap; }
  .rz__bar { display: flex; align-items: center; justify-content: space-between; padding: {{ $story ? '18px 44px 36px' : ($square ? '10px 30px 24px' : '14px 36px 28px') }}; }
  .rz__thumb { flex: none; width: {{ $story ? 118 : ($square ? 76 : 100) }}px; height: {{ $story ? 118 : ($square ? 76 : 100) }}px; border-radius: 50%; background: {{ $brand['surface'] }} center/cover no-repeat; }
  .rz__count { background: {{ $brand['primary'] }}; color: #fff; font-weight: 800; font-size: {{ $story ? 34 : ($square ? 24 : 28) }}px; padding: {{ $story ? '22px 34px' : '14px 26px' }}; border-radius: 999px; white-space: nowrap; }
@endsection

@section('card')
  <div class="re">
    @if($rows !== [])
      <div class="rz">
        <div class="rz__screen">
          <div class="rz__head">
            <div class="rz__kick">{{ $r['list_title'] ?? '' }}</div>
            <div class="rz__name">{{ $item['title'] }}</div>
          </div>
          <div class="rz__rows">
            @foreach($rows as $row)
              <div class="rz__row">
                <span class="rz__box">@include('templates.partials.recipe-icon', ['icon' => 'check', 'size' => $story ? 36 : ($square ? 26 : 30), 'color' => '#fff'])</span>
                <span class="rz__item">{{ $row }}</span>
                <span class="rz__tag">{{ $r['list_on_sale'] ?? '' }}</span>
              </div>
            @endforeach
          </div>
          <div class="rz__bar">
            <div class="rz__thumb" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif></div>
            <div class="rz__count">{{ $r['list_added'] ?? '' }}</div>
          </div>
        </div>
      </div>
    @else
      <div class="rc-photo" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif>
        @if(!empty($r['ai']))
          <div class="rc-ai">{{ $r['ai_label'] }}</div>
        @endif
      </div>
      <div class="re__line">{{ $r['cta_line'] ?? '' }}</div>
    @endif
    <div class="re__button">{{ $r['cta_get'] ?? ($brand['cta'] ?? $brand['name']) }}</div>
    <div class="re__where">{{ $r['link_sentence'] ?? '' }}</div>
    @if(!empty($r['ai']))
      <div class="re__ai">{{ $r['ai_note'] ?? '' }}</div>
    @endif
  </div>
@endsection
