@extends('templates._layout')

{{--
  A deal's card, after its hook: the same frame, with the two facts a shopper checks under the price (the
  lowest price in 30 days, how long it holds). In a direct video the discount is not stamped again here: it
  is on the cover, and often on the leaflet picture itself.
--}}
@php
    $card = true;
    $g = \App\Rendering\DealFrame::layout($width, $height, $card, $item['primary_shape'] ?? null);
@endphp

@section('styles')
@include('templates.partials.deal-frame-styles')
@endsection

@section('card')
  @include('templates.partials.deal-frame', ['footerCta' => 'Dodaj na listu'])
@endsection
