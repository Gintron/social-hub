@extends('templates._layout')

@section('styles')
  @if(! $item['primary_image'])
    /* Without a photo the hero is only an emoji on a gradient; it gives the facts the room instead. */
    .hero { flex-basis: {{ (int) round($height * 0.26) }}px; }
    .hero .placeholder { font-size: {{ $height > 1500 ? 200 : 140 }}px; }
  @endif
  .fact .value { font-size: 44px; }
  @if($height > 1500)
    /* 9:16: the card fills the middle band (the footer partial keeps the bottom for the app's UI),
       facts stack in one column and the right edge stays clear of the like/share buttons. */
    @if(! $item['primary_image'])
      .hero { flex-basis: {{ (int) round($height * 0.18) }}px; }
      .hero .placeholder { font-size: 170px; }
    @endif
    .body { padding: 56px 150px 40px 72px; gap: 28px; justify-content: safe center; }
    .title { font-size: 72px; }
    .subtitle { font-size: 44px; }
    .facts { grid-template-columns: 1fr; gap: 24px; }
    .fact .label { font-size: 28px; }
    .fact .value { font-size: 56px; white-space: normal; }
    .excerpt { font-size: 38px; -webkit-line-clamp: 4; }
  @endif
@endsection

@section('card')
  @include('templates.partials.hero')
  <div class="body">
    <div class="title"><span class="emoji">{{ $item['emoji'] }}</span> {{ $item['title'] }}</div>
    @if($item['subtitle'])
      <div class="subtitle">{{ $item['subtitle'] }}</div>
    @endif
    @if(!empty($item['facts']))
      <div class="facts">
        @foreach(array_slice($item['facts'], 0, 4) as $fact)
          <div class="fact">
            <div class="label">{{ $fact['label'] }}</div>
            <div class="value">{{ $fact['value'] }}</div>
          </div>
        @endforeach
      </div>
    @endif
    @if($item['excerpt'] && $height > 1100)
      <div class="excerpt">{{ $item['excerpt'] }}</div>
    @endif
  </div>
  @include('templates.partials.footer', ['cta' => 'Prijave: '.($item['url_display'] ?? '')])
@endsection
