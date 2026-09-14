@extends('templates._layout')

@section('styles')
  @if(! $item['primary_image'])
    /* Without a photo the hero is only an emoji on a gradient; it gives the facts the room instead. */
    .hero { flex-basis: {{ (int) round($height * 0.26) }}px; }
    .hero .placeholder { font-size: {{ $height > 1500 ? 200 : 140 }}px; }
  @endif
  .fact .value { font-size: 44px; }
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
