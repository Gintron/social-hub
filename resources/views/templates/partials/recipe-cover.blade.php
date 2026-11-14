{{-- The cover of a roundup of dishes: the headline, how many, up to four dishes with their shop, the label of the pictures. --}}
@php($rc = $item['recipe_cover'] ?? [])
<div class="rv">
  <div class="headline">{{ $item['title'] }}</div>
  {{-- The headline of a series often says how many already ("…: 5 jela iz akcija"); said twice it reads as a mistake. --}}
  @if(!empty($rc['count']) && !str_contains((string) $item['title'], $rc['count']))
    <div class="count">{{ $rc['count'] }}</div>
  @endif
  <div class="rv__grid">
    @foreach(array_slice($item['recipe_cards'] ?? [], 0, 4) as $dish)
      <div class="rv__dish" @if(!empty($dish['image'])) style="background-image: url('{{ $dish['image'] }}')" @endif>
        @if(!empty($dish['chain']))
          <div class="rc-plaque">{{ $dish['chain'] }}</div>
        @endif
      </div>
    @endforeach
  </div>
  <div class="rv__row">
    @if(!empty($rc['ai']))
      <div class="rv__ai">{{ $rc['ai_label'] }}</div>
    @else
      <div></div>
    @endif
    <div class="rv__swipe">{{ $rc['swipe'] ?? '' }} →</div>
  </div>
</div>
