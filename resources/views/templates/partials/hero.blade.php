<div class="hero">
  @if($item['primary_image'])
    <img src="{{ $item['primary_image'] }}" alt="">
  @else
    <div class="placeholder"><span class="emoji">{{ $item['emoji'] }}</span></div>
  @endif
  @if(!empty($item['badges']))
    <div class="badges">
      @foreach(array_slice($item['badges'], 0, 3) as $badge)
        <span class="badge">{{ $badge }}</span>
      @endforeach
    </div>
  @endif
  @if($item['logo_image'])
    <div class="logo-chip"><img src="{{ $item['logo_image'] }}" alt=""></div>
  @endif
</div>
