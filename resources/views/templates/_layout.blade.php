<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { width: {{ $width }}px; height: {{ $height }}px; overflow: hidden; }
  body {
    font-family: "Noto Sans", "Inter", "Liberation Sans", system-ui, -apple-system, sans-serif;
    color: {{ $brand['text'] }};
    background: {{ $brand['background'] }};
    -webkit-font-smoothing: antialiased;
  }
  .emoji { font-family: "Noto Color Emoji", "Apple Color Emoji", "Segoe UI Emoji", sans-serif; }
  .card { position: relative; width: 100%; height: 100%; display: flex; flex-direction: column; }
  .hero { position: relative; flex: 0 0 {{ (int) round($height * 0.46) }}px; background: {{ $brand['surface'] }}; overflow: hidden; }
  .hero img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .hero .placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 220px; background: linear-gradient(135deg, {{ $brand['primary'] }} 0%, {{ $brand['accent'] }} 100%); }
  .badges { position: absolute; left: 48px; top: 40px; display: flex; gap: 14px; flex-wrap: wrap; max-width: 80%; }
  .badge { background: {{ $brand['accent'] }}; color: #111; font-weight: 700; font-size: 28px; padding: 10px 22px; border-radius: 999px; line-height: 1.1; }
  .body { flex: 1; padding: 52px 64px 0 64px; display: flex; flex-direction: column; gap: 22px; }
  .title { font-size: 66px; font-weight: 800; line-height: 1.08; letter-spacing: -0.5px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
  .subtitle { font-size: 36px; color: {{ $brand['muted'] }}; font-weight: 600; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
  .facts { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 32px; margin-top: 8px; }
  .fact .label { font-size: 24px; letter-spacing: 2px; color: {{ $brand['muted'] }}; font-weight: 700; }
  .fact .value { font-size: 36px; font-weight: 700; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
  .excerpt { font-size: 30px; line-height: 1.35; color: #374151; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
  .footer { flex: 0 0 120px; display: flex; align-items: center; justify-content: space-between; padding: 0 64px; background: {{ $brand['primary'] }}; color: #fff; }
  .footer .site { font-size: 34px; font-weight: 800; letter-spacing: 0.5px; }
  .footer .cta { font-size: 30px; font-weight: 600; opacity: 0.95; }
  .footer img { height: 64px; width: auto; max-width: 320px; object-fit: contain; }
  .logo-chip { position: absolute; right: 48px; bottom: -44px; width: 140px; height: 140px; border-radius: 28px; background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,.18); display: flex; align-items: center; justify-content: center; overflow: hidden; }
  .logo-chip img { width: 100%; height: 100%; object-fit: contain; padding: 14px; }
  .price { display: flex; align-items: baseline; gap: 26px; margin-top: 6px; }
  .price .now { font-size: 110px; font-weight: 900; color: {{ $brand['primary'] }}; letter-spacing: -2px; line-height: 1; }
  .price .old { font-size: 44px; color: {{ $brand['muted'] }}; text-decoration: line-through; font-weight: 600; }
  .price .unit { font-size: 32px; color: {{ $brand['muted'] }}; font-weight: 600; }
  .discount { position: absolute; right: 48px; top: 40px; width: 190px; height: 190px; border-radius: 50%; background: #dc2626; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 62px; font-weight: 900; box-shadow: 0 10px 30px rgba(0,0,0,.25); transform: rotate(-8deg); }
  .valid { font-size: 28px; color: {{ $brand['muted'] }}; font-weight: 600; }
  @yield('styles')
</style>
</head>
<body>
<div class="card">
@yield('card')
</div>
</body>
</html>
