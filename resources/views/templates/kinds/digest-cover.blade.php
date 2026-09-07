@extends('templates._layout')

@section('styles')
  .cover { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 0 72px; gap: 28px;
           background: linear-gradient(135deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); color: #fff; }
  .cover .kicker { font-size: 34px; font-weight: 700; letter-spacing: 4px; text-transform: uppercase; opacity: .85; }
  .cover .headline { font-size: 96px; font-weight: 900; line-height: 1.02; letter-spacing: -2px; }
  .cover .sub { font-size: 38px; font-weight: 600; opacity: .92; }
  .thumbs { display: flex; gap: 16px; margin-top: 10px; }
  .thumbs div { width: 168px; height: 168px; border-radius: 20px; overflow: hidden; background: rgba(255,255,255,.22); flex: 0 0 auto; }
  .thumbs img { width: 100%; height: 100%; object-fit: cover; display: block; }
@endsection

@section('card')
  <div class="cover">
    @if(!empty($item['kicker']))
      <div class="kicker">{{ $item['kicker'] }}</div>
    @endif
    <div class="headline">{{ $item['title'] }}</div>
    @if(!empty($item['subtitle']))
      <div class="sub">{{ $item['subtitle'] }}</div>
    @endif
    @if(!empty($item['thumbnails']))
      <div class="thumbs">
        @foreach(array_slice($item['thumbnails'], 0, 4) as $thumb)
          <div>@if($thumb)<img src="{{ $thumb }}" alt="">@endif</div>
        @endforeach
      </div>
    @endif
  </div>
  @include('templates.partials.footer', ['cta' => $item['url_display'] ?? ($brand['site'] ?? '')])
@endsection
