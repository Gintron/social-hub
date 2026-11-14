{{--
  Styles shared by the dish's slides (kinds/recipe-hook, recipe, recipe-cta) and the roundup cover. The picture is an AI
  drawing 800 px wide, so it is never stretched over the frame: it sits as a picture of its own, on a blurred copy of itself
  where the frame is dark, full width where the card is light. Every slide that shows it carries its label.
  Numbers and words arrive ready ($item['recipe']): nothing here counts, rounds or translates.
--}}
@php($serif = '"Noto Serif", "Liberation Serif", "DejaVu Serif", Georgia, serif')
  .rc-bg { position: absolute; inset: -80px; background-size: cover; background-position: center; filter: blur(46px) brightness(.58) saturate(1.2); }
  .rc-photo { position: relative; flex: none; background-size: cover; background-position: center; border-radius: 56px;
              box-shadow: 0 30px 80px rgba(0,0,0,.38); }
  .rc-ai { position: absolute; left: 26px; top: 26px; z-index: 3; background: rgba(20,26,18,.74); color: #fff; font-size: 30px; font-weight: 700;
           letter-spacing: .3px; padding: 9px 22px; border-radius: 999px; line-height: 1.15; }
  .rc-plaque { position: absolute; z-index: 3; right: 28px; bottom: -38px; background: #fff; border-radius: 26px; padding: 14px 30px;
               font-weight: 800; font-size: 52px; line-height: 1.1; color: {{ $brand['text'] }}; box-shadow: 0 12px 34px rgba(0,0,0,.28);
               max-width: 80%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  /* The bottom padding is for the descenders: a clamped box clips at its own edge, and "j" and "p" end below a 1.04 line. */
  .rc-title { font-family: {!! $serif !!}; font-weight: 700; letter-spacing: -1px; line-height: 1.04; text-wrap: balance; padding-bottom: .14em;
              overflow: hidden; display: -webkit-box; -webkit-box-orient: vertical; }
  .rc-pill { display: inline-block; border-radius: 999px; font-weight: 800; background: #ffd84d; color: #1f2a21; }
  .rc-tiles { display: flex; gap: 20px; }
  .rc-tile { flex: 1 1 0; min-width: 0; background: {{ $brand['surface'] }}; border: 3px solid rgba(0,0,0,.09); border-radius: 32px; padding: 22px 26px; }
  .rc-tile .l { font-size: 25px; font-weight: 800; letter-spacing: 2px; color: {{ $brand['muted'] }}; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .rc-tile .v { margin-top: 4px; font-family: {!! $serif !!}; font-weight: 700; font-size: 58px; letter-spacing: -1px; line-height: 1.05; white-space: nowrap; }
  .rc-tile--accent .v { color: {{ $brand['primary'] }}; }
  .rc-chip { display: inline-flex; align-items: center; gap: 12px; background: rgba(0,0,0,.06); color: {{ $brand['primary'] }}; font-weight: 800; font-size: 38px;
             padding: 14px 26px; border-radius: 999px; margin: 0 14px 14px 0; }
