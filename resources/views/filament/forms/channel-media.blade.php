@php
    use App\Enums\ContentFormat;
    use App\Enums\Platform;
    use App\Models\PostVariant;
    use App\Publishing\Meta\InstagramPreflight;
    use App\Publishing\PublishProblem;

    /** @var int $variantId */
    // Read fresh on every render: a render job attaches media while this screen is open.
    $variant = PostVariant::query()->with(['media.poster', 'account', 'draft.brand', 'draft.contentItems'])->find($variantId);
    $media = $variant?->media ?? collect();
    $brand = $variant?->draft?->brand;
    $caption = (string) ($variant?->caption ?? '');
    $format = $variant?->format();
    $rendering = $variant?->isRendering() ?? false;
    $problem = $variant !== null ? app(PublishProblem::class)->of($variant) : null;
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

    // What the voice did for this video: spoke it (and what it said), failed (and why), or was not asked.
    $advice = \App\Publishing\FailureAdvice::for($variant?->error_code);
    $failure = match ($variant?->status) {
        \App\Enums\VariantStatus::Failed => ['danger', 'Objava nije izašla'],
        \App\Enums\VariantStatus::Skipped => ['warning', 'Objava je preskočena'],
        default => ['warning', 'Zadnji pokušaj nije uspio, hub pokušava ponovno'],
    };
    $voice = $video ? (array) data_get($video->params, 'voiceover', []) : [];
    $voiced = ($voice['status'] ?? null) === 'ok';
    $voiceWanted = $variant?->wantsVoiceover() ?? false;
@endphp
<div @if($rendering) wire:poll.3s @endif class="hub-media">
  @if($variant === null)
    <p class="hub-media__note">Kanal više ne postoji.</p>
  @else
    @if($variant->error_message)
      <x-filament::callout :color="$failure[0]" icon="heroicon-o-exclamation-circle" :heading="$failure[1]">
        <x-slot name="description">{{ $variant->error_message }}</x-slot>
        @if($advice)
          <x-slot name="footer"><strong>Što učiniti:</strong> {{ $advice }}</x-slot>
        @endif
      </x-filament::callout>
    @endif

    @if($problem !== null && ! $rendering)
      <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle" :description="$problem" />
    @endif

    @if($video && ! $rendering && $voiceWanted && ! $voiced)
      @if(($voice['status'] ?? null) === 'failed')
        <x-filament::callout color="warning" icon="heroicon-o-microphone" heading="Video je bez voice-overa">
          <x-slot name="description">{{ $voice['error'] ?? 'glas nije mogao nastati' }}. Objava ide i bez njega. Kad se uzrok ukloni, „Renderiraj ponovno“ ga dodaje.</x-slot>
        </x-filament::callout>
      @else
        <x-filament::callout color="warning" icon="heroicon-o-microphone" heading="Ovaj video nema voice-over"
                             description="Izrađen je prije nego što je uključen. „Renderiraj ponovno“ ga dodaje." />
      @endif
    @endif

    @if($warnings !== [])
      <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle" heading="Instagram bi ovo odbio">
        <x-slot name="footer">
          <ul class="hub-media__list">
            @foreach($warnings as $warning)<li>{{ $warning }}</li>@endforeach
          </ul>
        </x-slot>
      </x-filament::callout>
    @endif

    {{-- A preview of the post as the platform shows it. It keeps its own light colours on purpose: it stands for the platform's screen, not for this panel's theme. --}}
    <div @class(['hub-post', 'hub-post--vertical' => $vertical])>
      <div class="hub-post__head">
        <div class="hub-post__avatar" style="--hub-brand: {{ $brand?->colors['primary'] ?? '#1d4ed8' }}" aria-hidden="true">
          {{ mb_strtoupper(mb_substr($brand?->name ?? 'B', 0, 1)) }}
        </div>
        <div>
          <div class="hub-post__name">{{ $variant->account?->name ?? $brand?->name }}</div>
          <div class="hub-post__meta">{{ $variant->platform->label() }} · {{ $format->label() }}</div>
        </div>
      </div>

      @if($rendering)
        <div class="hub-post__placeholder" style="--hub-ratio: {{ $vertical ? '9/16' : '1/1' }}" role="status">
          <x-filament::loading-indicator class="hub-post__spinner" />
          <span>Renderiram: {{ mb_strtolower($format->label()) }}…</span>
          <span class="hub-post__meta">Pregled se osvježi sam.</span>
        </div>
      @elseif($format === ContentFormat::Link)
        <div class="hub-post__caption hub-post__caption--lead">{{ $caption }}</div>
        <div class="hub-post__link-card">
          <div class="hub-post__host">{{ parse_url((string) $variant->link_url, PHP_URL_HOST) }}</div>
          <div class="hub-post__name">{{ $item?->title ?? $variant->link_url }}</div>
          <div class="hub-post__meta">Facebook sam povuče sliku i opis sa stranice.</div>
        </div>
      @elseif($format === ContentFormat::Video && $video)
        <video src="{{ $video->publicUrl() }}" controls playsinline preload="metadata" class="hub-post__video"
               @if($video->poster) poster="{{ $video->poster->publicUrl() }}" @endif
               aria-label="Video objave"></video>
        <div class="hub-post__caption">{{ $caption }}</div>
        @if($voiced)
          <details class="hub-post__voice">
            <summary>
              <x-filament::icon icon="heroicon-o-microphone" class="hub-post__voice-icon" />
              Voice-over · {{ $voice['characters'] ?? 0 }} znakova
            </summary>
            <ol>
              @foreach((array) ($voice['script'] ?? []) as $line)
                {{-- What the voice was given, with numbers spelled out and the IPA of the words put in: the place to catch a wrong transcription. --}}
                @php($said = data_get($voice, 'clips.'.$loop->index.'.spoken'))
                <li @class(['is-silent' => ($line['text'] ?? '') === ''])>{{ ($line['text'] ?? '') !== '' ? $line['text'] : '(slajd samo uz glazbu)' }}
                  @if(filled($said))
                    <div class="hub-post__voice-said">Glas čita: {{ $said }}</div>
                  @endif
                </li>
              @endforeach
            </ol>
          </details>
        @endif
      @elseif($images->isNotEmpty())
        @unless($isInstagram)
          <div class="hub-post__caption hub-post__caption--lead">{{ $caption }}</div>
        @endunless
        <div class="hub-post__slides-wrap">
          @if($images->count() > 1)
            <div class="hub-post__count">{{ $images->count() }} slajda · povuci</div>
          @endif
          <div class="hub-post__slides" tabindex="0" role="region" aria-label="Slajdovi objave">
            @foreach($images as $asset)
              <img src="{{ $asset->publicUrl() }}" alt="Slajd {{ $loop->iteration }} od {{ $loop->count }}">
            @endforeach
          </div>
        </div>
        @if($isInstagram)
          <div class="hub-post__ig">
            <strong>{{ $variant->account?->name }}</strong>
            <span class="hub-post__ig-text">{{ $head }}</span>@if($tail !== '')<span class="hub-post__ig-more">… više</span>@endif
          </div>
        @endif
      @else
        <div class="hub-post__placeholder" style="--hub-ratio: {{ $vertical ? '9/16' : '1/1' }}">
          Medij za ovaj format još ne postoji — klikni „Renderiraj ponovno”.
        </div>
      @endif
    </div>

    <div class="hub-media__actions" x-data="{ copied: false, copy() { navigator.clipboard.writeText(@js($caption)).then(() => { this.copied = true; setTimeout(() => this.copied = false, 1500) }) } }">
      <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-clipboard-document" x-on:click="copy()">
        <span x-show="!copied">Kopiraj tekst</span><span x-show="copied" x-cloak>Kopirano</span>
      </x-filament::button>
      @unless($rendering || $format === ContentFormat::Link)
        @foreach($media as $asset)
          <x-filament::button tag="a" :href="$asset->publicUrl()" download target="_blank" rel="noopener" color="gray" size="sm" icon="heroicon-o-arrow-down-tray">Preuzmi {{ $asset->isVideo() ? 'video' : 'sliku' }} {{ $loop->count > 1 ? $loop->iteration : '' }}</x-filament::button>
        @endforeach
      @endunless
      @if($variant->platform->isManual() && $variant->account?->external_id && str_starts_with($variant->account->external_id, 'http'))
        <x-filament::button tag="a" :href="$variant->account->external_id" target="_blank" rel="noopener" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">Otvori grupu</x-filament::button>
      @endif
      @if($variant->permalink)
        <x-filament::button tag="a" :href="$variant->permalink" target="_blank" rel="noopener" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square">Otvori objavu</x-filament::button>
      @endif
    </div>

    <div class="hub-media__status">
      Status: <strong>{{ $variant->status->label() }}</strong>
      @if(! $rendering && ($first = $media->first()))
        · {{ $first->width }}×{{ $first->height }}@if($first->isVideo()), {{ number_format((float) $first->durationSeconds(), 1, ',', '') }} s @endif
      @endif
      @if($variant->published_at) · {{ $variant->published_at->timezone('Europe/Zagreb')->format('d.m.Y H:i') }} @endif
      @if($variant->error_code) · šifra <code>{{ $variant->error_code }}</code> @endif
    </div>
  @endif
</div>
