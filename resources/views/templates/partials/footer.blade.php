<div class="footer">
  @if($brand['logo'])
    <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}">
  @else
    <div class="site">{{ $brand['site'] ?? $brand['name'] }}</div>
  @endif
  @php($footerCta = $cta ?? ($item['url_display'] ?? ''))
  @if($footerCta !== '' && $footerCta !== ($brand['site'] ?? null))
    <div class="cta">{{ $footerCta }}</div>
  @endif
</div>
