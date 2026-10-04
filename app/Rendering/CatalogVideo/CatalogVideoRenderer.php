<?php

declare(strict_types=1);

namespace App\Rendering\CatalogVideo;

use App\Catalog\CatalogCopy;
use App\Catalog\CatalogDemo;
use App\Catalog\CatalogSceneBundle;
use App\Catalog\CatalogVideoPlan;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Voiceover\Narration;
use App\Voiceover\NarrationClip;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The "new catalog" video: Chromium draws the frames of a scene that is a copy of the app (resources/catalog-video),
 * ffmpeg mixes the voice, the music and the app's own "added" sound onto the frames of the taps, and encodes
 * H.264/yuv420p + AAC + faststart — what the uploaders of every channel take.
 *
 * It is a second kind of video next to the slideshow (VideoRenderer), not a variant of it: a slideshow is images
 * with a crossfade, this is one scene in motion. The slideshow's rules hold: the voice is an addition, never a
 * condition (a missing narration is a video with music and subtitles), and nothing here is read from the item
 * but what `raw.demo` carries.
 */
final class CatalogVideoRenderer
{
    /**
     * Stored as the asset's template_key, so a draft's catalog video can be found and reused.
     */
    public const TEMPLATE_KEY = 'video/catalog-demo';

    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public function __construct(
        private readonly CatalogSceneBundle $bundles,
        private readonly CatalogFrameRenderer $frames,
    ) {}

    /**
     * @param  string  $where  Where the link is, as the voice and the end card say it (CatalogCopy::WHERE_*).
     * @param  string|null  $audioPath  The brand's music; null leaves the voice and the taps alone on a silent bed.
     */
    public function render(
        Brand $brand,
        PostDraft $draft,
        ContentItem $item,
        CatalogDemo $demo,
        ?Narration $narration = null,
        string $where = CatalogCopy::WHERE_COMMENT,
        ?string $audioPath = null,
    ): MediaAsset {
        if ($audioPath !== null && ! is_file($audioPath)) {
            throw new RuntimeException("Zvučni zapis ne postoji: {$audioPath}");
        }

        $lines = CatalogCopy::voiceLines($demo, $where, $this->cta($brand));

        if ($narration !== null && count($narration->clips) !== count($lines)) {
            throw new RuntimeException('Voice-over treba jedan redak po rečenici videa ('.count($lines).').');
        }

        $plan = CatalogVideoPlan::build(
            $demo,
            $lines,
            $narration === null ? null : array_map(fn (?NarrationClip $clip): ?float => $clip?->seconds, $narration->clips),
        );

        $work = storage_path('app/tmp/catalog-video-'.Str::uuid());
        File::ensureDirectoryExists($work);

        try {
            $this->bundles->make($brand, $item, $demo, $plan, $where, $work);
            $summary = $this->frames->frames($work, $work.'/frames');

            $mix = $this->mix($work, $plan, $narration, $audioPath);
            $output = $work.'/video.mp4';
            $this->encode($work, $mix, $output);

            $binary = file_get_contents($output);

            if ($binary === false || mb_strlen($binary, '8bit') < 1024) {
                throw new RuntimeException('ffmpeg je proizveo prazan video.');
            }

            $disk = (string) config('hub.media_disk', 'public');
            $path = sprintf('media/%s/%s/%s.mp4', $brand->slug, now()->format('Y/m'), Str::uuid());
            Storage::disk($disk)->put($path, $binary, 'public');

            $poster = $this->poster($brand, $draft, $work, $plan, $disk);

            return MediaAsset::query()->create([
                'brand_id' => $brand->id,
                'post_draft_id' => $draft->id,
                'template_key' => self::TEMPLATE_KEY,
                'params' => [
                    'catalog_id' => $demo->catalogId,
                    'chain' => $demo->chain,
                    'taps' => array_map(fn (array $tap): string => $tap['product_id'], $demo->taps),
                    'total_cents' => $demo->totalCents,
                    'link_in' => $where,
                    'audio' => $audioPath === null ? null : basename($audioPath),
                    'timeline' => Arr::only($plan, ['duration', 'swipes', 'taps', 'list', 'cta']),
                    'frames' => $summary,
                    'mix' => $mix['loudness'],
                    'voiceover' => $narration === null ? null : [
                        ...$narration->describe(),
                        'starts' => array_map(fn (array $voice): float => $voice['at'], $plan['voice']),
                    ],
                ],
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'duration_ms' => (int) round($plan['duration'] * 1000),
                'poster_media_asset_id' => $poster?->id,
                'format' => 'mp4',
                'disk' => $disk,
                'path' => $path,
                'bytes' => mb_strlen($binary, '8bit'),
                'checksum' => hash('sha256', $binary),
            ]);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * The ffmpeg filter graph of the soundtrack, as one string: music (leveled, under the voice and pressed further
     * down while it speaks), each spoken sentence at its start, and the "added" sound on the frame of every tap.
     * Pure, so a test can read where each sound lands.
     *
     * @param  array<string, mixed>  $plan  CatalogVideoPlan::build()
     * @param  list<NarrationClip|null>  $clips  One per sentence.
     * @param  array{music: int|null, sound: int, voices: list<int|null>}  $inputs  Index of every input of the command.
     */
    public function soundtrackGraph(array $plan, array $clips, array $inputs, float $musicGainDb, float $soundGainDb): string
    {
        $total = (float) $plan['duration'];
        $parts = [];

        if ($inputs['music'] !== null) {
            $fadeOut = min(1.2, $total / 3);
            $parts[] = sprintf(
                '[%d:a]atrim=0:%.3f,asetpts=N/SR/TB,loudnorm=I=-16:TP=-1.5:LRA=11,aresample=44100,volume=%.1fdB,'
                .'afade=t=in:st=0:d=0.3,afade=t=out:st=%.3f:d=%.3f,aformat=sample_fmts=fltp:channel_layouts=stereo[bed]',
                $inputs['music'], $total, $musicGainDb, max(0.0, $total - $fadeOut), $fadeOut,
            );
        } else {
            $parts[] = sprintf('anullsrc=r=44100:cl=stereo,atrim=0:%.3f,asetpts=N/SR/TB,aformat=sample_fmts=fltp:channel_layouts=stereo[bed]', $total);
        }

        $voices = [];

        foreach ($clips as $index => $clip) {
            $input = $inputs['voices'][$index] ?? null;

            if ($clip === null || $input === null) {
                continue;
            }

            $delay = (int) round($plan['voice'][$index]['at'] * 1000);
            $parts[] = sprintf(
                '[%d:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,volume=%.1fdB,adelay=%d|%d[v%d]',
                $input, $clip->gainDb, $delay, $delay, $index,
            );
            $voices[] = "[v{$index}]";
        }

        // One "added" sound, one copy per tap, each starting on the frame its tap is drawn on.
        $taps = $plan['sounds'];
        $copies = implode('', array_map(fn (int $i): string => "[s{$i}]", array_keys($taps)));
        $parts[] = sprintf(
            '[%d:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,volume=%.1fdB,%s',
            $inputs['sound'], $soundGainDb, count($taps) === 1 ? 'anull[s0]' : sprintf('asplit=%d%s', count($taps), $copies),
        );

        $effects = [];

        foreach ($taps as $i => $at) {
            $delay = (int) round($at * 1000);
            $parts[] = sprintf('[s%d]adelay=%d|%d[fx%d]', $i, $delay, $delay, $i);
            $effects[] = "[fx{$i}]";
        }

        $parts[] = count($effects) === 1
            ? $effects[0].'anull[fx]'
            : sprintf('%samix=inputs=%d:duration=longest:dropout_transition=0:normalize=0[fx]', implode('', $effects), count($effects));

        $limiter = $this->limiter();

        if ($voices === []) {
            $parts[] = sprintf('[bed][fx]amix=inputs=2:duration=longest:dropout_transition=0:normalize=0,%s,atrim=0:%.3f,asetpts=N/SR/TB,aformat=channel_layouts=stereo[premix]', $limiter, $total);

            return implode(';', $parts);
        }

        // Lines never overlap each other (each has its own stretch of the video), so the mix only places them.
        $parts[] = count($voices) === 1
            ? $voices[0].'anull[said]'
            : sprintf('%samix=inputs=%d:duration=longest:dropout_transition=0:normalize=0[said]', implode('', $voices), count($voices));

        // The voice track runs the whole video, so the compressor's key never ends before the music does.
        $parts[] = sprintf('[said]apad=whole_dur=%.3f,asplit=2[voice][key]', $total);
        $parts[] = '[bed][key]sidechaincompress=threshold=0.03:ratio=4:attack=25:release=700:makeup=1[ducked]';
        $parts[] = sprintf(
            '[ducked][voice][fx]amix=inputs=3:duration=longest:dropout_transition=0:normalize=0,%s,'
            .'atrim=0:%.3f,asetpts=N/SR/TB,aformat=channel_layouts=stereo[premix]',
            $limiter,
            $total,
        );

        return implode(';', $parts);
    }

    /**
     * The peaks of the voice and of the "added" sound are shaved before the level is set: a mix whose highest
     * peak sits just under full scale cannot be brought up to −14 LUFS without going over the peak ceiling, so it
     * would come out a decibel quieter than asked. A ceiling a few dB lower on the premix leaves the room.
     */
    private function limiter(): string
    {
        $ceiling = (float) config('catalog_video.premix_ceiling_db', -3.5);

        return sprintf('alimiter=limit=%.4f:level=0:attack=5:release=60', 10 ** ($ceiling / 20));
    }

    /**
     * The soundtrack in two passes: mix everything, measure it, then bring the measured mix to the target
     * loudness in one linear step (no pumping, and the peak lands where it is told to).
     *
     * @param  array<string, mixed>  $plan
     * @return array{path: string, filter: string|null, loudness: array<string, mixed>}
     */
    private function mix(string $work, array $plan, ?Narration $narration, ?string $audioPath): array
    {
        $arguments = ['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'];
        $index = 0;
        $inputs = ['music' => null, 'sound' => 0, 'voices' => []];

        if ($audioPath !== null) {
            // Loop a short track, cut a long one; either way the video decides the length.
            $arguments = [...$arguments, '-stream_loop', '-1', '-i', $audioPath];
            $inputs['music'] = $index++;
        }

        $sound = (string) config('catalog_video.add_sound');

        if (! is_file($sound)) {
            throw new RuntimeException("Zvuk dodavanja ne postoji: {$sound}");
        }

        $arguments = [...$arguments, '-i', $sound];
        $inputs['sound'] = $index++;

        $clips = $narration?->clips ?? [];

        foreach ($clips as $i => $clip) {
            if ($clip !== null) {
                $arguments = [...$arguments, '-i', $clip->path];
                $inputs['voices'][$i] = $index++;
            }
        }

        $graph = $this->soundtrackGraph(
            $plan,
            $clips,
            $inputs,
            $narration?->musicGainDb ?? -14.0,
            (float) config('catalog_video.add_sound_gain_db', -4.0),
        );

        $premix = $work.'/premix.wav';
        $this->run([...$arguments, '-filter_complex', $graph, '-map', '[premix]', '-c:a', 'pcm_f32le', '-ar', '44100', $premix], 'Miks zvuka nije uspio');

        $target = (array) config('catalog_video.loudness');
        // AAC adds a few tenths of a dB to a peak that was exactly at the ceiling: the ceiling is aimed a little lower, so
        // that what leaves the encoder is at or under the one that was asked for.
        $ceiling = (float) $target['true_peak'] - (float) ($target['codec_headroom_db'] ?? 0.0);
        $measured = $this->measure($premix, (float) $target['lufs'], $ceiling);

        return [
            'path' => $premix,
            'filter' => $measured === null ? null : $this->loudnormFilter($measured, (float) $target['lufs'], $ceiling),
            'loudness' => ['target' => $target, 'premix' => $measured],
        ];
    }

    /**
     * @param  array<string, string>  $m  loudnorm's own measurement of the premix
     */
    private function loudnormFilter(array $m, float $lufs, float $peak): string
    {
        return sprintf(
            'loudnorm=I=%.1f:TP=%.1f:LRA=11:measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true',
            $lufs, $peak, $m['input_i'], $m['input_tp'], $m['input_lra'], $m['input_thresh'], $m['target_offset'],
        );
    }

    /**
     * @return array<string, string>|null Null for a track too quiet or short to measure; the premix is then used as it is.
     */
    private function measure(string $path, float $lufs, float $peak): ?array
    {
        $process = new Process([
            'ffmpeg', '-hide_banner', '-nostats', '-i', $path,
            '-af', sprintf('loudnorm=I=%.1f:TP=%.1f:LRA=11:print_format=json', $lufs, $peak), '-f', 'null', '-',
        ], timeout: 120);
        $process->run();

        if (! $process->isSuccessful() || preg_match_all('/\{[^{}]*"input_i"[^{}]*\}/s', $process->getErrorOutput(), $matches) < 1) {
            return null;
        }

        $data = json_decode((string) end($matches[0]), true);

        foreach (['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'] as $key) {
            if (! is_array($data) || ! isset($data[$key]) || ! is_numeric($data[$key]) || ! is_finite((float) $data[$key])) {
                return null;
            }
        }

        return array_map('strval', array_intersect_key($data, array_flip(['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'])));
    }

    /**
     * @param  array{path: string, filter: string|null, loudness: array<string, mixed>}  $mix
     */
    private function encode(string $work, array $mix, string $output): void
    {
        $this->run([
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
            '-framerate', (string) CatalogVideoPlan::FPS, '-i', $work.'/frames/%05d.jpg',
            '-i', $mix['path'],
            '-vf', 'scale=in_range=full:out_range=tv:out_color_matrix=bt709,format=yuv420p',
            '-c:v', 'libx264', '-preset', 'medium', '-crf', (string) (int) config('catalog_video.crf', 19),
            '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-r', (string) CatalogVideoPlan::FPS,
            '-colorspace', 'bt709', '-color_primaries', 'bt709', '-color_trc', 'bt709', '-color_range', 'tv',
            ...($mix['filter'] === null ? [] : ['-af', $mix['filter'].',aresample=44100']),
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100',
            '-shortest', '-movflags', '+faststart',
            $output,
        ], 'ffmpeg nije uspio');
    }

    /**
     * The cover: the second after the first tap, when the card is marked, the toast says it and the title says
     * what happened. Instagram takes it as the Reel's cover (`cover_url`); Filament shows it before playback.
     *
     * @param  array<string, mixed>  $plan
     */
    private function poster(Brand $brand, PostDraft $draft, string $work, array $plan, string $disk): ?MediaAsset
    {
        $frame = (int) round(($plan['taps'][0]['at'] + 0.5) * CatalogVideoPlan::FPS);
        $file = sprintf('%s/frames/%05d.jpg', $work, $frame);

        if (! is_file($file)) {
            return null;
        }

        $binary = (string) file_get_contents($file);
        $path = sprintf('media/%s/%s/%s.jpg', $brand->slug, now()->format('Y/m'), Str::uuid());
        Storage::disk($disk)->put($path, $binary, 'public');

        return MediaAsset::query()->create([
            'brand_id' => $brand->id,
            'post_draft_id' => $draft->id,
            'template_key' => 'video/catalog-poster',
            'params' => ['frame' => $frame],
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'format' => 'jpg',
            'disk' => $disk,
            'path' => $path,
            'bytes' => mb_strlen($binary, '8bit'),
            'checksum' => hash('sha256', $binary),
        ]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments, string $failure): void
    {
        $process = new Process($arguments, timeout: (int) config('catalog_video.encode_timeout', 300));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($failure.': '.mb_substr(mb_trim($process->getErrorOutput() ?: $process->getOutput()), 0, 500));
        }
    }

    private function cta(Brand $brand): string
    {
        $cta = mb_trim((string) data_get($brand->voice, 'cta', ''));

        return $cta === '' ? 'Preuzmi '.$brand->name : $cta;
    }
}
