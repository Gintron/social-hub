@php($serif = '"Noto Serif", "Liberation Serif", "DejaVu Serif", Georgia, serif')
@include('templates.partials.recipe-styles')
  .rv { flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden; color: #fff;
        padding: {{ $story ? '150px 64px 30px' : ($square ? '40px 56px 20px' : '56px 64px 24px') }}; gap: {{ $story ? 24 : 18 }}px;
        background: linear-gradient(160deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .rv .headline { font-family: {!! $serif !!}; font-weight: 700; letter-spacing: -2px; line-height: 1.02; font-size: {{ $story ? 100 : ($square ? 70 : 96) }}px;
                  padding-bottom: .14em; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; text-wrap: balance; }
  .rv .count { font-size: {{ $story ? 46 : 34 }}px; font-weight: 800; opacity: .94; }
  .rv__grid { display: grid; grid-template-columns: repeat({{ $square ? 4 : 2 }}, 1fr); gap: {{ $story ? 22 : 16 }}px; }
  .rv__dish { position: relative; border-radius: 34px; background: center/cover no-repeat; height: {{ $story ? 300 : ($square ? 220 : 330) }}px; box-shadow: 0 12px 34px rgba(0,0,0,.25); overflow: hidden; }
  .rv__dish .rc-plaque { left: 14px; right: auto; bottom: 14px; font-size: 30px; padding: 7px 18px; border-radius: 18px; }
  .rv__row { display: flex; justify-content: space-between; align-items: center; margin-top: auto; }
  .rv__ai { background: rgba(20,26,18,.74); font-size: 28px; font-weight: 700; padding: 9px 22px; border-radius: 999px; }
  .rv__swipe { font-size: {{ $story ? 48 : 40 }}px; font-weight: 800; }
