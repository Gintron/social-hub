{{--
  The small line icons of the dish's slides (time, portions, the day the offer ends, a tick). Drawn here, not set as text: the renderer's
  fonts are not the ones on a laptop, and an emoji or a symbol that is missing from them is a hole in the slide.
  Needs $icon (clock|plate|hourglass|check), $size (px) and $color.
--}}
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="{{ $color }}" stroke-width="{{ $icon === 'check' ? 3.4 : 2.6 }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  @switch($icon)
    @case('clock')
      <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>
      @break
    @case('plate')
      <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4.6"/>
      @break
    @case('hourglass')
      <path d="M6 3h12M6 21h12M7 3c0 6 5 6 5 9s-5 3-5 9M17 3c0 6-5 6-5 9s5 3 5 9"/>
      @break
    @default
      <path d="M4 12.5l5 5L20 6.5"/>
  @endswitch
</svg>
