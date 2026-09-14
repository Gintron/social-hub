@extends('templates._layout')

{{--
  Last slide: where to go and what to do there. The same for every item of a brand, so a viewer who
  watched to the end always meets the brand's own call to action and address.
--}}
@php($story = $height > 1500)

@section('styles')
  .end { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
         gap: {{ $story ? 48 : 36 }}px; padding: {{ $story ? '260px 90px 440px' : '80px 80px' }}; color: #fff;
         background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .end .logo { width: {{ $story ? 240 : 200 }}px; height: {{ $story ? 240 : 200 }}px; border-radius: 48px; background: #fff;
               display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: 0 14px 40px rgba(0,0,0,.22); }
  .end .logo img { width: 100%; height: 100%; object-fit: contain; padding: 24px; }
  .end .headline { font-size: {{ $story ? 88 : 72 }}px; font-weight: 900; line-height: 1.05; letter-spacing: -1px; }
  .end .where { font-size: {{ $story ? 50 : 42 }}px; font-weight: 800; background: rgba(255,255,255,.2); padding: 20px 44px; border-radius: 999px; }
  .end .share { font-size: {{ $story ? 40 : 34 }}px; font-weight: 600; opacity: .92; }
@endsection

@section('card')
  <div class="end">
    @if($brand['logo'])
      <div class="logo"><img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}"></div>
    @endif
    <div class="headline">{{ $brand['cta'] ?: ($item['cta_label'] ?? $brand['name']) }}</div>
    @if($brand['site'] ?? $item['url_display'] ?? null)
      <div class="where">🔗 {{ $brand['site'] ?? $item['url_display'] }}</div>
    @endif
    <div class="share">Spremi objavu i pošalji je prijatelju <span class="emoji">📤</span></div>
  </div>
@endsection
