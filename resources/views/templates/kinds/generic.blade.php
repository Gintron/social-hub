@extends('templates._layout')

@section('card')
  @include('templates.partials.hero')
  <div class="body">
    <div class="title"><span class="emoji">{{ $item['emoji'] }}</span> {{ $item['title'] }}</div>
    @if($item['subtitle'])
      <div class="subtitle">{{ $item['subtitle'] }}</div>
    @endif
    @if($item['excerpt'])
      <div class="excerpt">{{ $item['excerpt'] }}</div>
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
  </div>
  @include('templates.partials.footer')
@endsection
