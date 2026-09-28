@extends('templates._layout')

@php($cards = array_slice($item['job_cards'] ?? [], 0, 3))

@section('styles')
  .job-roundup { position: relative; flex: 1; display: flex; align-items: center; overflow: hidden;
    padding: 250px 180px 430px 72px; color: #fff;
    background: linear-gradient(145deg, color-mix(in srgb, {{ $brand['primary'] }} 80%, #111827), #201630); }
  .job-roundup::before { content: ''; position: absolute; width: 850px; height: 850px; border: 100px solid {{ $brand['accent'] }};
    opacity: .35; border-radius: 50%; right: -570px; top: -360px; }
  .job-roundup .content { position: relative; width: 100%; display: flex; flex-direction: column; gap: 32px; }
  .job-roundup .kicker { color: {{ $brand['accent'] }}; font-size: 32px; font-weight: 900; letter-spacing: 2px; }
  .job-roundup .headline { font-size: {{ mb_strlen($item['title']) > 32 ? 70 : 88 }}px; font-weight: 900; line-height: 1.05;
    letter-spacing: -2px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
  .job-roundup .rows { display: flex; flex-direction: column; gap: 15px; }
  .job-roundup .row { display: flex; align-items: center; gap: 22px; min-height: 165px; padding: 18px 25px;
    border-radius: 26px; background: #fff; color: {{ $brand['text'] }}; }
  .job-roundup .number { flex: 0 0 58px; color: {{ $brand['primary'] }}; font-size: 58px; font-weight: 900; }
  .job-roundup .copy { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8px; }
  .job-roundup .role { font-size: 39px; font-weight: 900; line-height: 1.12; overflow: hidden; display: -webkit-box;
    -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
  .job-roundup .facts { display: flex; flex-wrap: wrap; gap: 14px; margin: 0; font-size: 29px; font-weight: 700;
    color: {{ $brand['muted'] }}; }
  .job-roundup .site { font-size: 32px; font-weight: 800; opacity: .9; }
@endsection

@section('card')
  <div class="job-roundup">
    <div class="content">
      <div class="kicker">IZBOR POSLOVA</div>
      <div class="headline">{{ $item['title'] }}</div>
      <div class="rows">
        @foreach($cards as $index => $job)
          <div class="row">
            <div class="number">{{ $index + 1 }}</div>
            <div class="copy">
              <div class="role">{{ $job['title'] }}</div>
              <div class="facts">
                @if(!empty($job['pay']))<span>{{ $job['pay'] }}</span>@endif
                @if(!empty($job['location']))<span>· {{ $job['location'] }}</span>@endif
              </div>
            </div>
          </div>
        @endforeach
      </div>
      <div class="site">{{ $brand['site'] ?? $brand['name'] }}</div>
    </div>
  </div>
@endsection
