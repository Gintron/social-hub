@extends('templates._layout')

@php
  $story = $height > 1500;
  $digest = ($item['kind'] ?? null) === 'digest';
  $title = $item['job_display']['title'] ?? $item['title'];
  $action = $digest ? 'Pogledaj oglase' : (filled($item['cta_label'] ?? null) ? $item['cta_label'] : 'Pogledaj oglas');
  $site = $brand['site'] ?? $item['url_display'] ?? $brand['name'];
@endphp

@section('styles')
  .job-end { position: relative; flex: 1; display: flex; align-items: center; overflow: hidden;
    padding: {{ $story ? '250px 180px 440px 72px' : '80px 82px' }}; color: #fff;
    background: linear-gradient(150deg, #191325 0%, color-mix(in srgb, {{ $brand['primary'] }} 78%, #16121f) 100%); }
  .job-end::before { content: ''; position: absolute; width: 820px; height: 820px; border-radius: 50%;
    background: {{ $brand['accent'] }}; opacity: .14; right: -520px; bottom: -300px; }
  .job-end .content { position: relative; display: flex; flex-direction: column; gap: {{ $story ? 44 : 32 }}px; width: 100%; }
  .job-end .brandline { display: flex; align-items: center; gap: 22px; font-size: {{ $story ? 32 : 29 }}px;
    font-weight: 800; }
  .job-end .brandline img { width: 62px; height: 62px; object-fit: contain; {!! $brand['logo_filter'] ?? '' !!} }
  .job-end .eyebrow { color: {{ $brand['accent'] }}; font-size: {{ $story ? 34 : 29 }}px;
    font-weight: 900; letter-spacing: 2px; }
  .job-end .headline { font-size: {{ $story ? 102 : 86 }}px; font-weight: 900; line-height: 1.03; letter-spacing: -3px; }
  .job-end .listing { font-size: {{ $story ? 48 : 40 }}px; line-height: 1.18; font-weight: 700;
    opacity: .92; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2;
    -webkit-box-orient: vertical; overflow-wrap: anywhere; }
  .job-end .action { align-self: flex-start; max-width: 100%; background: #fff; color: {{ $brand['primary'] }};
    border-radius: 24px; padding: 26px 38px; font-size: {{ $story ? 54 : 46 }}px; font-weight: 900;
    line-height: 1.14; box-shadow: 0 16px 40px rgba(0,0,0,.2); }
  .job-end .site { font-size: {{ $story ? 44 : 38 }}px; font-weight: 800; overflow-wrap: anywhere; }
@endsection

@section('card')
  <div class="job-end">
    <div class="content">
      <div class="brandline">
        @if($brand['logo'])<img src="{{ $brand['logo'] }}" alt="">@endif
        <span>{{ $brand['name'] }}</span>
      </div>
      <div class="eyebrow">{{ $digest ? 'VIŠE OGLASA' : 'PRONAĐI OGLAS' }}</div>
      <div class="headline">{{ $digest ? 'Nađi posao za sebe' : 'Tvoj sljedeći posao?' }}</div>
      <div class="listing">{{ $title }}</div>
      <div class="action">{{ $action }}</div>
      <div class="site">{{ $site }}</div>
    </div>
  </div>
@endsection
