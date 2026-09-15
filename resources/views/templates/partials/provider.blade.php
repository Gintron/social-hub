{{--
  The plaque naming whose offer this is. The logo when the feed sends one — a wordmark already says
  the name, so it is not repeated — otherwise the name in type, but only where the template is not
  printing it anyway ($name).
--}}
@php($provider = $item['provider'] ?? [])
@php($providerLogo = $provider['logo'] ?? null)
@php($providerName = $provider['name'] ?? null)

@if($providerLogo || (($name ?? false) && $providerName))
  <div class="provider {{ $class ?? '' }}">
    @if($providerLogo)
      <img src="{{ $providerLogo }}" alt="{{ $providerName }}">
    @else
      <div class="name">{{ $providerName }}</div>
    @endif
  </div>
@endif
