{{--
  The deal hook and the deal card share one frame (see deal-frame-styles): the shop, the leaflet picture, and
  one yellow block with the name and the price. The card adds the two facts under it. The shop comes first
  and is always named: a price with no shop behind it reads as the brand's own.
--}}
@php
    $provider = $item['provider'] ?? [];
    $hasProvider = ! empty($provider['logo']) || ! empty($provider['name']);
    $hasImage = ! empty($item['primary_image']);
    $cut = (bool) ($item['primary_shape']['cut'] ?? true);
    $hook = $item['hook'] ?? [];
    $parts = $hook['price_parts'] ?? null;
    $figure = $hook['figure'] ?? null;
    $old = $hook['figure_old'] ?? null;
    $discount = $item['price']['discount_pct'] ?? null;
    // A direct video puts the discount on the cover and the hook; the card that follows does not say it a third time.
    $direct = $g['story'] && ($brand['video_style'] ?? null) === 'direct';
    $showStamp = filled($discount) && ! ($card && $direct);

    $chips = [];
    if ($card) {
        $facts = array_slice($item['facts'] ?? [], 0, 2);
        $chips = array_map(fn (array $fact): array => ['label' => $fact['label'], 'value' => $fact['value']], $facts);
        // The date the offer ends, when no fact already says it: the shopper has to reach the shop in time.
        if (! empty($item['expires_at'])) {
            $chips = array_slice($chips, 0, 1);
            $chips[] = ['label' => 'VRIJEDI DO', 'value' => $item['expires_at']];
        }
    }
@endphp
<div class="deal">
  <div class="deal__in">
    @if($hasProvider)
      <div class="deal-top">
        <div class="eyebrow">AKCIJA U TRGOVINI</div>
        @include('templates.partials.provider', ['name' => true, 'class' => ''])
      </div>
    @endif

    @if($hasImage)
      <div class="deal-media" style="width: {{ $g['tw'] }}px; height: {{ $g['th'] }}px;">
        @include('templates.partials.leaflet', ['src' => $item['primary_image'], 'cut' => $cut])
        @if($showStamp)
          <div class="stamp" style="left: {{ $g['stampLeft'] }}px;">−{{ $discount }}%</div>
        @elseif(blank($discount) && ! empty($item['badges']))
          <div class="badges">
            @foreach(array_slice($item['badges'], 0, 3) as $badge)
              <span class="badge">{{ $badge }}</span>
            @endforeach
          </div>
        @endif
      </div>
    @else
      <div class="deal-emoji"><span class="emoji">{{ $item['emoji'] ?? '🛒' }}</span></div>
    @endif

    <div class="deal-offer{{ $hasImage ? ' deal-offer--over' : '' }}">
      @if($showStamp && ! $hasImage)
        <div class="stamp stamp--offer">−{{ $discount }}%</div>
      @endif
      <div class="deal-offer__name">{{ $item['title_display'] ?? $item['title'] }}</div>
      @if($parts || $figure)
        <div class="deal-offer__price">
          @if($parts)
            <span class="n">{{ $parts['whole'] }},{{ $parts['cents'] }}</span><span class="c{{ mb_strlen($parts['unit']) > 1 ? ' c--long' : '' }}">{{ $parts['unit'] }}</span>
          @else
            <span class="n n--text">{{ $figure }}</span>
          @endif
          @if($old)
            <span class="o">{{ $old }}</span>
          @endif
        </div>
      @endif
    </div>

    @if($chips)
      <div class="deal-chips">
        @foreach($chips as $chip)
          <div class="deal-chip"><div class="l">{{ $chip['label'] }}</div><div class="v">{{ $chip['value'] }}</div></div>
        @endforeach
      </div>
    @endif
  </div>
</div>
@unless($g['story'])
  @include('templates.partials.footer', ['cta' => $footerCta])
@endunless
