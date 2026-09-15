{{--
  The plaque naming whose offer this is.

  A wordmark (SPAR, Konzum, ~5:1) is the name written out, so it stands alone. A badge (Lidl, Tommy,
  about 1:1) cannot: at a wordmark's height it covers a fifth of the area and its own lettering ends
  up tiny, so it gets the height instead of the width and the name is set beside it — the lockup
  every shop uses itself. Without a logo the name carries the plaque alone, but only where the
  template is not printing it anyway ($name).
--}}
@php($provider = $item['provider'] ?? [])
@php($providerLogo = $provider['logo'] ?? null)
@php($providerName = $provider['name'] ?? null)
{{-- An unmeasurable mark is laid out as a wordmark: that is the shape a feed sends most often. --}}
@php($compact = ($provider['logo_ratio'] ?? null) !== null && $provider['logo_ratio'] < 1.8)
@php($withName = ($name ?? false) && filled($providerName) && (! $providerLogo || $compact))

@if($providerLogo || $withName)
  @php($classes = array_filter(['provider', $class ?? '', $providerLogo && $compact ? 'provider--compact' : '']))
  <div class="{{ implode(' ', $classes) }}">
    @if($providerLogo)
      <img src="{{ $providerLogo }}" alt="{{ $providerName }}">
    @endif
    @if($withName)
      <div class="name">{{ $providerName }}</div>
    @endif
  </div>
@endif
