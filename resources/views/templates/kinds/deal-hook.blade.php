@extends('templates._layout')

{{--
  First slide of a deal's video or carousel: whose offer it is, the leaflet picture, and the name and price on
  one yellow block, with the discount stamped on the picture. The deal card (deal) follows with the same frame.
  Nothing else is on it: the number and the shop are what a thumb scrolling past has to catch.
--}}
@php
    $card = false;
    $g = \App\Rendering\DealFrame::layout($width, $height, $card, $item['primary_shape'] ?? null);
@endphp

@section('styles')
@include('templates.partials.deal-frame-styles')
@endsection

@section('card')
  @include('templates.partials.deal-frame', ['footerCta' => $item['cta_label'] ?? null])
@endsection
