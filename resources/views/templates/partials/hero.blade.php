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
  {{-- Logo only: these cards print the provider's name under the title, so the plaque would say it twice. --}}
  @include('templates.partials.provider', ['class' => 'provider--hero'])
</div>
