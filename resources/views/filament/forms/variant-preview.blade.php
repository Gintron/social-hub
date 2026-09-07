@php
    use App\Enums\Platform;
    use App\Publishing\Meta\InstagramPreflight;

    /** @var \App\Models\PostVariant|null $variant */
    $variant = $getRecord();
    $media = $variant?->media ?? collect();
    $brand = $variant?->draft?->brand;
    $caption = (string) ($variant?->caption ?? '');
    $isInstagram = $variant?->platform === Platform::InstagramBusiness;

    // Instagram truncates the caption in feed; show where the "more" link would land.
    $foldAt = 125;
    $head = mb_substr($caption, 0, $foldAt);
    $tail = mb_substr($caption, $foldAt);

    $warnings = $isInstagram && $variant !== null
        ? app(InstagramPreflight::class)->warnings($variant, $media)
        : [];
@endphp
<div style="display:flex;flex-direction:column;gap:12px">
  @if($variant === null)
    <div style="font-size:13px;opacity:.7">Pregled je dostupan nakon spremanja.</div>
  @else
    @if($warnings !== [])
      <div style="border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:10px;padding:10px 12px;font-size:12px;line-height:1.5">
        <strong>Instagram bi ovo odbio:</strong>
        <ul style="margin:6px 0 0 16px;padding:0">
          @foreach($warnings as $warning)<li>{{ $warning }}</li>@endforeach
        </ul>
      </div>
    @endif

    <div style="border:1px solid rgba(128,128,128,.35);border-radius:12px;overflow:hidden;background:#fff;color:#111;max-width:520px">
      <div style="display:flex;align-items:center;gap:10px;padding:12px">
        <div style="width:40px;height:40px;border-radius:50%;background:{{ $brand?->colors['primary'] ?? '#1d4ed8' }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700">
          {{ mb_strtoupper(mb_substr($brand?->name ?? 'B', 0, 1)) }}
        </div>
        <div>
          <div style="font-weight:600;font-size:14px">{{ $variant->account?->name ?? $brand?->name }}</div>
          <div style="font-size:12px;opacity:.6">{{ $variant->platform->label() }} · pregled</div>
        </div>
      </div>

      @php($video = $media->first(fn ($asset) => $asset->isVideo()))
      @if($video)
        <video src="{{ $video->publicUrl() }}" controls playsinline preload="metadata"
               @if($video->poster) poster="{{ $video->poster->publicUrl() }}" @endif
               style="display:block;width:100%;max-height:520px;background:#000"></video>
        <div style="padding:12px;font-size:13px;white-space:pre-wrap;line-height:1.4">{{ $caption }}</div>
      @elseif($isInstagram)
        <div style="position:relative">
          @if($media->count() > 1)
            <div style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,.65);color:#fff;font-size:12px;padding:3px 9px;border-radius:999px;z-index:1">1/{{ $media->count() }}</div>
          @endif
          <div style="display:flex;overflow-x:auto;scroll-snap-type:x mandatory">
            @foreach($media as $asset)
              <img src="{{ $asset->publicUrl() }}" alt="" style="display:block;width:100%;flex:0 0 100%;scroll-snap-align:start">
            @endforeach
          </div>
        </div>
        <div style="padding:12px;font-size:13px;line-height:1.4">
          <span style="font-weight:600">{{ $variant->account?->name }}</span>
          <span style="white-space:pre-wrap">{{ $head }}</span>@if($tail !== '')<span style="opacity:.55">… više</span>@endif
        </div>
      @else
        <div style="padding:0 12px 12px;font-size:14px;white-space:pre-wrap;line-height:1.4">{{ $caption }}</div>
        @foreach($media as $asset)<img src="{{ $asset->publicUrl() }}" alt="" style="display:block;width:100%">@endforeach
      @endif

      @if($media->isEmpty())
        <div style="padding:12px;font-size:12px;opacity:.6;background:#f3f4f6">Slika se još renderira ili nije zatražena — koristi akciju „Renderiraj sliku ponovno”.</div>
      @endif
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:8px;font-size:13px" x-data="{ copied: false }">
      <button type="button"
              x-on:click="navigator.clipboard.writeText(@js($caption)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
              style="padding:6px 12px;border:1px solid rgba(128,128,128,.4);border-radius:8px;background:transparent;cursor:pointer">
        <span x-show="!copied">📋 Kopiraj tekst</span><span x-show="copied" x-cloak>✅ Kopirano</span>
      </button>
      @foreach($media as $asset)
        <a href="{{ $asset->publicUrl() }}" download target="_blank" rel="noopener" style="padding:6px 12px;border:1px solid rgba(128,128,128,.4);border-radius:8px;text-decoration:none">⬇️ Preuzmi {{ $asset->isVideo() ? 'video' : 'sliku' }} {{ $loop->count > 1 ? $loop->iteration : '' }}</a>
      @endforeach
      @if($variant->platform->isManual() && $variant->account?->external_id && str_starts_with($variant->account->external_id, 'http'))
        <a href="{{ $variant->account->external_id }}" target="_blank" rel="noopener" style="padding:6px 12px;border:1px solid rgba(128,128,128,.4);border-radius:8px;text-decoration:none">↗ Otvori grupu</a>
      @endif
      @if($variant->permalink)
        <a href="{{ $variant->permalink }}" target="_blank" rel="noopener" style="padding:6px 12px;border:1px solid rgba(128,128,128,.4);border-radius:8px;text-decoration:none">↗ Otvori objavu</a>
      @endif
    </div>

    <div style="font-size:12px;opacity:.75">
      Status: <strong>{{ $variant->status->label() }}</strong>
      @foreach($media as $asset)
        @if($loop->first) · {{ $asset->width }}×{{ $asset->height }}@if($asset->isVideo()), {{ number_format((float) $asset->durationSeconds(), 1, ',', '') }} s @endif @endif
      @endforeach
      @if($variant->published_at) · {{ $variant->published_at->timezone('Europe/Zagreb')->format('d.m.Y H:i') }} @endif
      @if($variant->error_message) <br><span style="color:#dc2626">{{ $variant->error_code }}: {{ $variant->error_message }}</span> @endif
    </div>
  @endif
</div>
