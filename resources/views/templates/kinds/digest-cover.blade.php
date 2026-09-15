@extends('templates._layout')

{{--
  First slide of a roundup, and the cover frame of its Reel: the headline, the deepest discount and
  the products themselves, large enough to stop a scrolling thumb. On 9:16 the app's interface
  covers the top, so the content starts lower; on a square there is only room for a row of four.
--}}
@php
    $story = $height > 1500;
    $square = $height <= 1080;
    $thumbs = array_slice($item['thumbnails'] ?? [], 0, 4);
    // A grid of two looks deliberate; a single picture does not, so it needs at least two.
    $showThumbs = count($thumbs) >= 2;
@endphp

@section('styles')
  .cover { flex: 1; min-height: 0; display: flex; flex-direction: column; justify-content: center; overflow: hidden;
           padding: {{ $story ? '150px 72px 40px' : ($square ? '0 72px' : '56px 72px') }}; gap: {{ $square ? 22 : 26 }}px;
           background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); color: #fff; }
  .cover .kicker { font-size: 34px; font-weight: 800; letter-spacing: 4px; text-transform: uppercase; opacity: .85; }
  .cover .headline { font-size: {{ $story ? 118 : ($square ? 92 : 104) }}px; font-weight: 900; line-height: 1.02; letter-spacing: -2px; }
  .cover .badge-deal { align-self: flex-start; background: #dc2626; color: #fff; font-size: {{ $square ? 44 : 54 }}px; font-weight: 900;
                       padding: 10px 30px; border-radius: 999px; box-shadow: 0 10px 30px rgba(0,0,0,.25); }
  .cover .sub { font-size: 38px; font-weight: 700; opacity: .92; }
  .cover .provider { align-self: flex-start; }
  .thumbs { display: grid; gap: {{ $square ? 16 : 22 }}px; margin-top: 6px;
            grid-template-columns: repeat({{ $square ? 4 : 2 }}, 1fr); }
  .thumbs div { height: {{ $story ? 300 : ($square ? 200 : 250) }}px; border-radius: 28px; overflow: hidden; background: #fff;
                box-shadow: 0 12px 34px rgba(0,0,0,.22); }
  .thumbs img { width: 100%; height: 100%; object-fit: contain; padding: 12px; display: block; }
@endsection

@section('card')
  <div class="cover">
    @if(!empty($item['kicker']))
      <div class="kicker">{{ $item['kicker'] }}</div>
    @endif
    <div class="headline">{{ $item['title'] }}</div>
    @if(!empty($item['badge']))
      <div class="badge-deal">{{ $item['badge'] }}</div>
    @endif
    {{-- Only when the whole roundup is one chain's; a mixed one stays the brand's own selection. --}}
    @include('templates.partials.provider', ['name' => true])
    @if(!empty($item['subtitle']))
      <div class="sub">{{ $item['subtitle'] }}</div>
    @endif
    @if($showThumbs)
      <div class="thumbs">
        @foreach($thumbs as $thumb)
          <div><img src="{{ $thumb }}" alt=""></div>
        @endforeach
      </div>
    @endif
  </div>
  @include('templates.partials.footer', ['cta' => $item['url_display'] ?? ($brand['site'] ?? '')])
@endsection
