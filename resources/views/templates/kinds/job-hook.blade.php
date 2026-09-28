@extends('templates._layout')

@php
  $story = $height > 1500;
  $job = $item['job_display'] ?? [];
  $title = $job['title'] ?? $item['title'];
  $pay = $job['pay'] ?? null;
  $paySize = mb_strlen((string) $pay) > 18 ? 76 : (mb_strlen((string) $pay) > 12 ? 92 : 116);
@endphp

@section('styles')
  .job-hook { position: relative; flex: 1; display: flex; align-items: center; overflow: hidden;
    padding: {{ $story ? '250px 180px 430px 72px' : '70px 82px' }};
    color: #fff; background: linear-gradient(145deg, color-mix(in srgb, {{ $brand['primary'] }} 80%, #111827) 0%, #201630 100%); }
  .job-hook::before { content: ''; position: absolute; width: 780px; height: 780px; right: -430px; top: -250px;
    border: 100px solid color-mix(in srgb, {{ $brand['accent'] }} 42%, transparent); border-radius: 50%; }
  .job-hook::after { content: ''; position: absolute; width: 440px; height: 440px; left: -290px; bottom: 80px;
    border: 65px solid rgba(255,255,255,.08); border-radius: 50%; }
  .job-hook .content { position: relative; z-index: 1; display: flex; flex-direction: column; gap: {{ $story ? 42 : 28 }}px; width: 100%; }
  .job-hook .topline { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
  .job-hook .tag { background: {{ $brand['accent'] }}; color: #201630; padding: 12px 26px; border-radius: 999px;
    font-size: {{ $story ? 31 : 27 }}px; font-weight: 900; letter-spacing: 2px; }
  .job-hook .site { font-size: {{ $story ? 29 : 25 }}px; font-weight: 800; opacity: .9; }
  .job-hook .headline { font-size: {{ $story ? (mb_strlen($title) > 55 ? 66 : 78) : (mb_strlen($title) > 55 ? 60 : 72) }}px;
    font-weight: 900; line-height: 1.08; letter-spacing: -2px; overflow: hidden; display: -webkit-box;
    -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow-wrap: anywhere; }
  .job-hook .pay { align-self: flex-start; max-width: 100%; background: #fff; color: {{ $brand['text'] }};
    border-radius: 36px; padding: {{ $story ? '28px 38px 32px' : '24px 34px 28px' }};
    box-shadow: 0 20px 50px rgba(0,0,0,.22); }
  .job-hook .pay-label { font-size: {{ $story ? 29 : 26 }}px; font-weight: 900; color: {{ $brand['primary'] }};
    letter-spacing: 2px; text-transform: uppercase; }
  .job-hook .pay-value { margin-top: 8px; font-size: {{ $story ? $paySize : $paySize - 8 }}px; font-weight: 900;
    line-height: 1.08; letter-spacing: -3px; overflow-wrap: anywhere; }
  .job-hook .meta { display: flex; gap: 20px; flex-wrap: wrap; align-items: center; }
  .job-hook .meta-item { min-width: 0; max-width: 100%; padding: 14px 24px; border-radius: 18px;
    background: rgba(255,255,255,.14); border: 2px solid rgba(255,255,255,.24);
    font-size: {{ $story ? 35 : 31 }}px; line-height: 1.2; font-weight: 700;
    overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
  .job-hook .meta-item strong { color: {{ $brand['accent'] }}; }
@endsection

@section('card')
  <div class="job-hook">
    <div class="content">
      <div class="topline">
        <div class="tag">POSAO</div>
        <div class="site">{{ $brand['site'] ?? $brand['name'] }}</div>
      </div>
      <div class="headline">{{ $title }}</div>
      @if($pay)
        <div class="pay">
          <div class="pay-label">{{ $job['pay_label'] ?? 'NAKNADA' }}</div>
          <div class="pay-value">{{ $pay }}</div>
        </div>
      @endif
      @if(!empty($job['location']) || !empty($item['subtitle']))
        <div class="meta">
          @if(!empty($job['location']))<div class="meta-item"><strong>●</strong>&nbsp; {{ $job['location'] }}</div>@endif
          @if(!empty($item['subtitle']))<div class="meta-item">{{ $item['subtitle'] }}</div>@endif
        </div>
      @endif
    </div>
  </div>
@endsection
