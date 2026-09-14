<div class="footer">
  {{-- The address stays next to the logo: a mark-only logo would otherwise hide where to go. --}}
  <div class="brand">
    @if($brand['logo'])
      <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">
    @endif
    <div class="site">{{ $brand['site'] ?? $brand['name'] }}</div>
  </div>
  @php($footerCta = $cta ?? ($item['url_display'] ?? ''))
  @if($footerCta !== '' && $footerCta !== ($brand['site'] ?? null))
    <div class="cta">{{ $footerCta }}</div>
  @endif
</div>
@if($height > 1500)
  {{-- On 9:16 the app's caption and buttons cover the bottom; the brand colour runs on under them instead of content. --}}
  <div style="flex: 0 0 420px; background: {{ $brand['primary'] }};"></div>
@endif
