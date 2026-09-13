@php
    use App\Enums\ContentFormat;
    use App\Enums\Platform;
    use App\Models\PostVariant;
    use App\Publishing\FormatCheck;
    use App\Publishing\Meta\InstagramPreflight;

    /** @var int $variantId */
    // Read fresh on every render: a render job attaches media while this screen is open.
    $variant = PostVariant::query()->with(['media.poster', 'account', 'draft.brand', 'draft.contentItems'])->find($variantId);
    $media = $variant?->media ?? collect();
    $brand = $variant?->draft?->brand;
    $caption = (string) ($variant?->caption ?? '');
    $format = $variant?->format();
    $rendering = $variant?->isRendering() ?? false;
    $problem = $variant !== null ? app(FormatCheck::class)->problem($variant) : null;
    $isInstagram = $variant?->platform === Platform::InstagramBusiness;
    $vertical = $format === ContentFormat::Video || $variant?->platform === Platform::TikTok;

    // Instagram truncates the caption in feed; show where the "more" link would land.
    $foldAt = 125;
    $head = mb_substr($caption, 0, $foldAt);
    $tail = mb_substr($caption, $foldAt);

    $warnings = $isInstagram && ! $rendering && $problem === null
        ? app(InstagramPreflight::class)->warnings($variant, $media)
        : [];

    $video = $media->first(fn ($asset) => $asset->isVideo());
    $images = $media->reject(fn ($asset) => $asset->isVideo())->values();
    $item = $variant?->draft?->contentItems->first();
@endphp
<div @if($rendering) wire:poll.3s @endif style="display:flex;flex-direction:column;gap:12px">
  @if($variant === null)
    <div style="font-size:13px;opacity:.7">Kanal više ne postoji.</div>
  @else
    @if($problem !== null && ! $rendering)
      <div style="border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:10px;padding:10px 12px;font-size:12px;line-height:1.5">
        {{ $problem }}
      </div>
    @endif

    @if($warnings !== [])
      <div style="border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:10px;padding:10px 12px;font-size:12px;line-height:1.5">
        <strong>Instagram bi ovo odbio:</strong>
        <ul style="margin:6px 0 0 16px;padding:0">
          @foreach($warnings as $warning)<li>{{ $warning }}</li>@endforeach
        </ul>
      </div>
    @endif

    <div style="border:1px solid rgba(128,128,128,.35);border-radius:12px;overflow:hidden;background:#fff;color:#111;max-width:{{ $vertical ? '360px' : '520px' }}">
      <div style="display:flex;align-items:center;gap:10px;padding:12px">
        <div style="width:36px;height:36px;border-radius:50%;background:{{ $brand?->colors['primary'] ?? '#1d4ed8' }};color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700">
          {{ mb_strtoupper(mb_substr($brand?->name ?? 'B', 0, 1)) }}
        </div>
        <div>
          <div style="font-weight:600;font-size:14px">{{ $variant->account?->name ?? $brand?->name }}</div>
          <div style="font-size:12px;opacity:.6">{{ $variant->platform->label() }} · {{ $format->label() }}</div>
        </div>
      </div>

      @if($rendering)
        <div style="aspect-ratio:{{ $vertical ? '9/16' : '1/1' }};max-height:520px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;background:#f3f4f6;color:#6b7280;font-size:13px;text-align:center;padding:16px">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.2-8.56"/></svg>
          <span>Renderiram: {{ mb_strtolower($format->label()) }}…</span>
          <span style="font-size:12px;opacity:.8">Pregled se osvježi sam.</span>
        </div>
        <style>@keyframes spin{to{transform:rotate(360deg)}}</style>
      @elseif($format === ContentFormat::Link)
        <div style="padding:0 12px 12px;font-size:14px;white-space:pre-wrap;line-height:1.4">{{ $caption }}</div>
        <div style="margin:0 12px 12px;border:1px solid rgba(128,128,128,.35);border-radius:8px;padding:10px 12px;background:#f9fafb">
          <div style="font-size:11px;text-transform:uppercase;opacity:.6">{{ parse_url((string) $variant->link_url, PHP_URL_HOST) }}</div>
          <div style="font-weight:600;font-size:14px">{{ $item?->title ?? $variant->link_url }}</div>
          <div style="font-size:12px;opacity:.6">Facebook sam povuče sliku i opis sa stranice.</div>
        </div>
      @elseif($format === ContentFormat::Video && $video)
        <video src="{{ $video->publicUrl() }}" controls playsinline preload="metadata"
               @if($video->poster) poster="{{ $video->poster->publicUrl() }}" @endif
               style="display:block;width:100%;max-height:560px;background:#000"></video>
        <div style="padding:12px;font-size:13px;white-space:pre-wrap;line-height:1.4">{{ $caption }}</div>
      @elseif($images->isNotEmpty())
        @unless($isInstagram)
          <div style="padding:0 12px 12px;font-size:14px;white-space:pre-wrap;line-height:1.4">{{ $caption }}</div>
        @endunless
        <div style="position:relative">
          @if($images->count() > 1)
            <div style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,.65);color:#fff;font-size:12px;padding:3px 9px;border-radius:999px;z-index:1">{{ $images->count() }} slajda · povuci</div>
          @endif
          <div style="display:flex;overflow-x:auto;scroll-snap-type:x mandatory">
            @foreach($images as $asset)
              <img src="{{ $asset->publicUrl() }}" alt="" style="display:block;width:100%;flex:0 0 100%;scroll-snap-align:start">
            @endforeach
          </div>
        </div>
        @if($isInstagram)
          <div style="padding:12px;font-size:13px;line-height:1.4">
            <span style="font-weight:600">{{ $variant->account?->name }}</span>
            <span style="white-space:pre-wrap">{{ $head }}</span>@if($tail !== '')<span style="opacity:.55">… više</span>@endif
          </div>
        @endif
      @else
        <div style="aspect-ratio:{{ $vertical ? '9/16' : '1/1' }};max-height:420px;display:flex;align-items:center;justify-content:center;background:#f3f4f6;color:#6b7280;font-size:13px;text-align:center;padding:16px">
          Medij za ovaj format još ne postoji — klikni „Renderiraj ponovno”.
        </div>
      @endif
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:8px;font-size:13px" x-data="{ copied: false }">
      <button type="button"
              x-on:click="navigator.clipboard.writeText(@js($caption)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
              style="padding:6px 12px;border:1px solid rgba(128,128,128,.4);border-radius:8px;background:transparent;cursor:pointer">
        <span x-show="!copied">📋 Kopiraj tekst</span><span x-show="copied" x-cloak>✅ Kopirano</span>
      </button>
      @unless($rendering || $format === ContentFormat::Link)
        @foreach($media as $asset)
          <a href="{{ $asset->publicUrl() }}" download target="_blank" rel="noopener" style="padding:6px 12px;border:1px solid rgba(128,128,128,.4);border-radius:8px;text-decoration:none">⬇️ Preuzmi {{ $asset->isVideo() ? 'video' : 'sliku' }} {{ $loop->count > 1 ? $loop->iteration : '' }}</a>
        @endforeach
      @endunless
      @if($variant->platform->isManual() && $variant->account?->external_id && str_starts_with($variant->account->external_id, 'http'))
        <a href="{{ $variant->account->external_id }}" target="_blank" rel="noopener" style="padding:6px 12px;border:1px solid rgba(128,128,128,.4);border-radius:8px;text-decoration:none">↗ Otvori grupu</a>
      @endif
      @if($variant->permalink)
        <a href="{{ $variant->permalink }}" target="_blank" rel="noopener" style="padding:6px 12px;border:1px solid rgba(128,128,128,.4);border-radius:8px;text-decoration:none">↗ Otvori objavu</a>
      @endif
    </div>

    <div style="font-size:12px;opacity:.75">
      Status: <strong>{{ $variant->status->label() }}</strong>
      @if(! $rendering && ($first = $media->first()))
        · {{ $first->width }}×{{ $first->height }}@if($first->isVideo()), {{ number_format((float) $first->durationSeconds(), 1, ',', '') }} s @endif
      @endif
      @if($variant->published_at) · {{ $variant->published_at->timezone('Europe/Zagreb')->format('d.m.Y H:i') }} @endif
      @if($variant->error_message) <br><span style="color:#dc2626">{{ $variant->error_code }}: {{ $variant->error_message }}</span> @endif
    </div>
  @endif
</div>
