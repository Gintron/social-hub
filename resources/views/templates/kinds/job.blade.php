@extends('templates._layout')

@php
  $story = $height > 1500;
  $square = $height <= 1080;
  $job = $item['job_display'] ?? [];
  $title = $job['title'] ?? $item['title'];
  $extras = array_slice($job['extras'] ?? [], 0, $square ? 1 : 2);
@endphp

@section('styles')
  .job-card { position: relative; flex: 1; min-height: 0; display: flex; align-items: center; overflow: hidden;
    padding: {{ $story ? '240px 180px 410px 72px' : ($square ? '48px 64px' : '65px 72px') }};
    background: {{ $brand['surface'] }}; }
  .job-card::before { content: ''; position: absolute; width: 760px; height: 760px; border-radius: 50%;
    background: {{ $brand['accent'] }}; opacity: .12; top: -470px; right: -350px; }
  .job-card .content { position: relative; width: 100%; display: flex; flex-direction: column;
    gap: {{ $story ? 35 : ($square ? 22 : 30) }}px; }
  .job-card .topline { display: flex; align-items: center; justify-content: space-between; gap: 20px;
    font-size: {{ $story ? 28 : 25 }}px; font-weight: 900; letter-spacing: 1px; }
  .job-card .topline .label { color: {{ $brand['primary'] }}; }
  .job-card .topline .site { color: {{ $brand['muted'] }}; letter-spacing: 0; text-align: right; }
  .job-card .headline { font-size: {{ $story ? (mb_strlen($title) > 55 ? 62 : 72) : ($square ? 56 : 66) }}px;
    font-weight: 900; line-height: 1.07; letter-spacing: -1.5px; overflow: hidden; display: -webkit-box;
    -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow-wrap: anywhere; }
  .job-card .employer { display: flex; align-items: center; gap: 16px; min-width: 0;
    font-size: {{ $story ? 38 : 33 }}px; font-weight: 700; color: {{ $brand['muted'] }}; line-height: 1.18; }
  .job-card .employer img { flex: 0 0 auto; width: 64px; height: 64px; object-fit: contain;
    background: #fff; border-radius: 12px; padding: 5px; }
  .job-card .employer span { overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow-wrap: anywhere; }
  .job-card .rule { width: 100%; height: 6px; border-radius: 99px; background: {{ $brand['primary'] }}; }
  .job-card .details { display: flex; flex-direction: column; gap: {{ $story ? 18 : 14 }}px; }
  .job-card .detail { background: #fff; border-radius: 22px; padding: {{ $story ? '23px 28px' : '18px 24px' }};
    border-left: 10px solid {{ $brand['accent'] }}; box-shadow: 0 8px 24px rgba(22,28,45,.06); }
  .job-card .detail--pay { background: {{ $brand['primary'] }}; color: #fff; border-left-color: {{ $brand['accent'] }}; }
  .job-card .detail-label { font-size: {{ $story ? 25 : 22 }}px; font-weight: 900; letter-spacing: 1.5px;
    text-transform: uppercase; color: {{ $brand['muted'] }}; }
  .job-card .detail--pay .detail-label { color: #fff; opacity: .86; }
  .job-card .detail-value { margin-top: 7px; font-size: {{ $story ? 45 : ($square ? 37 : 42) }}px;
    font-weight: 800; line-height: 1.15; overflow: hidden; display: -webkit-box;
    -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow-wrap: anywhere; }
  .job-card .badges { position: static; max-width: 100%; }
  .job-card .badge { background: #fff; color: {{ $brand['primary'] }}; border: 2px solid {{ $brand['primary'] }};
    font-size: {{ $story ? 26 : 23 }}px; }
@endsection

@section('card')
  <div class="job-card">
    <div class="content">
      <div class="topline">
        <span class="label">DETALJI POSLA</span>
        <span class="site">{{ $brand['site'] ?? $brand['name'] }}</span>
      </div>
      <div class="headline">{{ $title }}</div>
      @if($item['subtitle'])
        <div class="employer">
          @if(!empty($item['provider']['logo']))<img src="{{ $item['provider']['logo'] }}" alt="">@endif
          <span>{{ $item['subtitle'] }}</span>
        </div>
      @endif
      <div class="rule"></div>
      <div class="details">
        @if(!empty($job['pay']))
          <div class="detail detail--pay"><div class="detail-label">{{ $job['pay_label'] }}</div><div class="detail-value">{{ $job['pay'] }}</div></div>
        @endif
        @if(!empty($job['location']))
          <div class="detail"><div class="detail-label">LOKACIJA</div><div class="detail-value">{{ $job['location'] }}</div></div>
        @endif
        @foreach($extras as $fact)
          <div class="detail"><div class="detail-label">{{ $fact['label'] }}</div><div class="detail-value">{{ $fact['value'] }}</div></div>
        @endforeach
      </div>
      @if(!empty($item['badges']))
        <div class="badges">
          @foreach(array_slice($item['badges'], 0, 2) as $badge)<span class="badge">{{ $badge }}</span>@endforeach
        </div>
      @endif
    </div>
  </div>
  @unless($story)
    @include('templates.partials.footer', ['cta' => $item['cta_label'] ?? null])
  @endunless
@endsection
