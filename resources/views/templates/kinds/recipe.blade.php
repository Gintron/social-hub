@extends('templates._layout')

{{--
  A dish: its picture, its name and the three numbers the post is about (ingredients on sale, what they come to, what the leaflet
  saves). No ingredient shows a product, a brand or a price: those belong to people with access, and a post is public. When the
  brand asks for it (`voice.recipe_highlights`) and the source sent them, the main ingredients stand under the name as chips.
  The footer is the brand's; on 9:16 its colour runs on under the app's interface, so everything sits above y = 1350.
--}}
@php
    $r = $item['recipe'] ?? [];
    $story = $height > 1500;
    $square = $height <= 1080;
    $chips = array_slice($r['highlights'] ?? [], 0, 4);
    // The chips take a row or two, so the picture gives them the room: the three numbers must stay above the footer.
    $photoHeight = match (true) {
        $story => $chips === [] ? 760 : 640,
        $square => $chips === [] ? 520 : 360,
        default => $chips === [] ? 700 : 540,
    };
    // A name is never cut with an ellipsis: the longer it is, the smaller it is set (German compounds are long: "Thunfisch mit warmem
    // Tomaten-Gemüse" does not fit two lines at the size of "Trofie s kozicama"), and a very long one gets a third line.
    $baseSize = $story ? 84 : ($square ? 58 : 72);
    $length = mb_strlen((string) $item['title']);
    $scale = match (true) {
        $length <= 26 => 1.0,
        $length <= 38 => 0.84,
        $length <= 52 => 0.72,
        default => 0.62,
    };
    $titleSize = (int) round($baseSize * $scale);
    $titleLines = $length > 52 ? 3 : 2;
    $pad = $story ? 64 : 56;
@endphp

@section('styles')
@include('templates.partials.recipe-styles')
  .rd { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; background: {{ $brand['background'] }}; }
  .rd__photo { position: relative; flex: 0 0 {{ $photoHeight }}px; background: {{ $brand['surface'] }} center/cover no-repeat; }
  .rd__photo .rc-plaque { right: auto; left: 32px; bottom: -34px; font-size: {{ $square ? 38 : 46 }}px; }
  .rd__body { flex: 1; min-height: 0; padding: {{ $square ? 52 : 66 }}px {{ $pad }}px 0; display: flex; flex-direction: column; gap: {{ $square ? 14 : 20 }}px; }
  .rd .rc-title { font-size: {{ $titleSize }}px; -webkit-line-clamp: {{ $titleLines }}; color: {{ $brand['text'] }}; hyphens: auto; }
  .rd__meta { font-size: {{ $square ? 28 : 34 }}px; font-weight: 700; color: {{ $brand['muted'] }}; }
  .rd .rc-tile { padding: {{ $square ? '14px 20px' : '20px 24px' }}; }
  .rd .rc-tile .v { font-size: {{ $square ? 44 : 54 }}px; }
  .rd .rc-chip { font-size: {{ $square ? 28 : 34 }}px; padding: {{ $square ? '8px 18px' : '12px 24px' }}; margin: 0 10px 10px 0; }
@endsection

@section('card')
  <div class="rd">
    <div class="rd__photo" @if($item['primary_image']) style="background-image: url('{{ $item['primary_image'] }}')" @endif>
      @if(!empty($r['ai']))
        <div class="rc-ai">{{ $r['ai_label'] }}</div>
      @endif
      @if(!empty($r['chain']))
        <div class="rc-plaque">{{ $r['chain'] }}</div>
      @endif
    </div>
    <div class="rd__body">
      <div class="rc-title">{{ $item['title'] }}</div>
      <div class="rd__meta">{{ $r['meta'] ?? '' }}</div>
      @if($chips !== [])
        <div>
          @foreach($chips as $chip)
            <span class="rc-chip"><span>✓</span>{{ $chip }}</span>
          @endforeach
        </div>
      @endif
      <div class="rc-tiles">
        @foreach(($r['tiles'] ?? []) as $tile)
          <div class="rc-tile{{ !empty($tile['accent']) ? ' rc-tile--accent' : '' }}">
            <div class="l">{{ $tile['label'] }}</div>
            <div class="v">{{ $tile['value'] }}</div>
          </div>
        @endforeach
      </div>
    </div>
  </div>
  @include('templates.partials.footer', ['cta' => $r['footer'] ?? ''])
@endsection
