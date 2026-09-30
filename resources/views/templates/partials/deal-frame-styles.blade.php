{{--
  Styles of the deal hook and the deal card, which stack the same parts (kinds/deal-hook, kinds/deal).
  Geometry comes from App\Rendering\DealFrame. The price block is yellow on every brand: like the red
  discount stamp it is a shop signal, not the brand's colour, and it has to read on the brand's gradient.
--}}
  .deal { flex: 1; min-height: 0; overflow: hidden; display: flex; flex-direction: column; justify-content: center;
          padding: {{ $g['padTop'] }}px 0 {{ $g['padBottom'] }}px; color: #fff;
          background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .deal__in { zoom: {{ $g['zoom'] }}; display: flex; flex-direction: column; }
  .deal-top { display: flex; align-items: center; gap: 26px; padding: 0 72px; }
  .deal-top .eyebrow { font-size: 30px; line-height: 1.3; max-width: 200px; color: rgba(255,255,255,.85); }
  .deal-media { position: relative; flex: none; margin: 30px auto 0; }
@include('templates.partials.leaflet-styles')
@if($card)
  .leaflet { transform: rotate(-1.6deg); }
@endif
  .stamp { position: absolute; top: -82px; width: 220px; height: 220px; z-index: 3; display: flex; align-items: center; justify-content: center;
           border-radius: 50%; background: #dc2626; color: #fff; font-size: 74px; font-weight: 900; letter-spacing: -2px;
           transform: rotate(9deg); box-shadow: 0 14px 34px rgba(0,0,0,.3); }
  /* No picture to hang off: it sits above the block's corner, clear of the name. */
  .stamp--offer { top: -190px; right: 36px; }
  .deal-emoji { text-align: center; font-size: 190px; line-height: 1; margin-top: 30px; }
  .deal-offer { position: relative; z-index: 2; margin: 30px 60px 0; padding: 40px 56px 44px; color: {{ $brand['text'] }}; background: #ffd84d;
                border-radius: 40px; transform: rotate(-1.2deg); box-shadow: 0 26px 60px rgba(0,0,0,.4); }
  .deal-offer--over { margin-top: -{{ \App\Rendering\DealFrame::OVERLAP }}px; }
  .deal-offer__name { font-size: 62px; font-weight: 900; line-height: 1.06; letter-spacing: -.5px; text-wrap: balance;
                      overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
  .deal-offer__price { display: flex; align-items: baseline; gap: 20px; margin-top: 10px; }
  .deal-offer__price .n { font-size: 216px; font-weight: 900; letter-spacing: -8px; line-height: 1; }
  .deal-offer__price .n--text { font-size: 110px; letter-spacing: -2px; }
  .deal-offer__price .c { font-size: 92px; font-weight: 800; margin-left: -10px; }
  .deal-offer__price .c--long { font-size: 54px; }
  .deal-offer__price .o { font-size: 54px; font-weight: 800; color: #6c5a10; margin-left: 12px; text-decoration: line-through; text-decoration-thickness: 5px; }
  .deal-chips { display: flex; gap: 20px; margin: 40px 60px 0; }
  .deal-chip { flex: 1 1 0; min-width: 0; padding: 18px 28px; border-radius: 28px; background: rgba(255,255,255,.14); border: 2px solid rgba(255,255,255,.3); }
  .deal-chip .l { font-size: 24px; font-weight: 800; letter-spacing: 2px; color: rgba(255,255,255,.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .deal-chip .v { margin-top: 2px; font-size: 44px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
