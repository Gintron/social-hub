{{-- A leaflet crop shown as an object (see leaflet-styles). $src: its data URI; $cut: TemplateData::tileShape()['cut']. --}}
<div class="leaflet">
  @unless($cut)
    <img class="leaflet__fill" src="{{ $src }}" alt="">
  @endunless
  <img class="leaflet__img{{ $cut ? ' leaflet__img--cut' : '' }}" src="{{ $src }}" alt="">
</div>
