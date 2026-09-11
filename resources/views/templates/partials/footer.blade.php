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
