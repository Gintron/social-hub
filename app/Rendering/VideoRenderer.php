<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A vertical slideshow out of the images the hub already renders.
 *
 * Reels and TikTok want video, but the content is the same content: rather than a second design
 * system, the slides are the existing templates rendered at 1080x1920 and stitched with a
 * crossfade. Everything happens with ffmpeg, which the container already carries.
 *
 * Slides drift in a slow zoom and a brand track can play underneath. A brand can choose direct
 * cuts for a sharper rhythm; crossfades remain the default for existing campaigns.
 */
final class VideoRenderer
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    /**
     * How long each card stays up. Three seconds (Marijan, 25 Sep 2026): two was too short to read
     * the product, the price and whose offer it is. A series can still set its own
     * (`seconds_per_slide`); none did, so this is what every video uses.
     */
    public const DEFAULT_SECONDS_PER_SLIDE = 3.0;

    public const DEFAULT_TRANSITION_SECONDS = 0.35;

    /**
     * The brand's end card is the one slide that has to be read, not glanced at: the call to
     * action, a sentence on what the brand does and where to get it. At two seconds it went by
     * before the reason did; the slides before it keep their quicker rhythm.
     */
    public const END_CARD_SECONDS = 3.5;

    /**
     * Stored as the asset's template_key, so a draft's video can be found and reused.
     */
    public const TEMPLATE_KEY = 'video/slideshow';

    private const FPS = 30;

    /**
     * Below three seconds Instagram and TikTok both reject the upload.
     */
    private const MIN_TOTAL_SECONDS = 3.0;

    /**
     * How far a slide zooms over its duration. Kept small and centred so the template's text
     * never leaves the frame.
     */
    private const ZOOM = 0.05;

    private const AUDIO_FADE_OUT_SECONDS = 1.2;

    public function __construct(private readonly TemplateRegistry $templates) {}

    /**
     * A video that closes with the brand's end card holds it for END_CARD_SECONDS; the card is
     * recognised by its template, so every caller gets the same hold.
     *
     * @param  Collection<int, MediaAsset>  $slides  Rendered images, in the order they should play.
     * @param  string|null  $audioPath  Absolute path to a track to play underneath; null keeps a silent track.
     * @param  list<float>|null  $slideSeconds  Explicit storyboard timings, one per slide.
     */
    public function slideshow(
        Brand $brand,
        Collection $slides,
        ?PostDraft $draft = null,
        float $secondsPerSlide = self::DEFAULT_SECONDS_PER_SLIDE,
        ?float $transitionSeconds = null,
        ?string $audioPath = null,
        bool $motion = true,
        ?array $slideSeconds = null,
    ): MediaAsset {
        if ($slides->isEmpty()) {
            throw new RuntimeException('Video treba barem jedan slajd.');
        }

        if ($audioPath !== null && ! is_file($audioPath)) {
            throw new RuntimeException("Zvučni zapis ne postoji: {$audioPath}");
        }

        $secondsPerSlide = max(1.5, $secondsPerSlide);
        // Job cards contain compact facts and a single action. Let each complete card arrive at
        // once, instead of fading one block of text across another during the short read time.
        $jobVideo = $slides->every(fn (MediaAsset $slide): bool => in_array($slide->template_key, [
            'kinds/job-hook-story', 'kinds/job-story', 'kinds/job-cta-story', 'kinds/job-digest-cover-story',
        ], true));
        $direct = data_get($brand->voice, 'video_style') === 'direct' || $jobVideo;
        $transitionSeconds ??= $direct ? 0.0 : self::DEFAULT_TRANSITION_SECONDS;

        $count = $slides->count();
        $lastSlideSeconds = $this->templates->isClosing($slides->last()->template_key) ? self::END_CARD_SECONDS : null;
        $durations = $this->durations($count, $secondsPerSlide, $lastSlideSeconds);

        if ($slideSeconds !== null) {
            if (count($slideSeconds) !== $count || array_any($slideSeconds, fn ($seconds): bool => ! is_numeric($seconds) || ! is_finite((float) $seconds) || $seconds < 1.5 || $seconds > 10)) {
                throw new RuntimeException('Svaki slajd treba trajanje od 1,5 do 10 sekundi.');
            }

            $durations = array_values(array_map('floatval', $slideSeconds));
        } elseif ($direct && $count > 1 && preg_match('~^kinds/(hook|comparison-hook|digest-cover|job-hook|job-digest-cover)-~', (string) $slides->first()->template_key)) {
            // A roundup shows several roles at once; its cover needs the whole default hold.
            $durations[0] = $slides->first()->template_key === 'kinds/job-digest-cover-story'
                ? max(3.0, $secondsPerSlide)
                : min($jobVideo ? 2.4 : 2.5, $secondsPerSlide);
        }

        $transitionSeconds = max(0.0, min($transitionSeconds, min($durations) / 2));
        $total = array_sum($durations) - ($count - 1) * $transitionSeconds;

        // A single slide would otherwise produce a 3-second clip only by accident; make the floor explicit.
        if ($total < self::MIN_TOTAL_SECONDS) {
            $durations[$count - 1] += self::MIN_TOTAL_SECONDS - $total;
            $total = array_sum($durations) - ($count - 1) * $transitionSeconds;
        }

        $output = tempnam(sys_get_temp_dir(), 'hub-video-').'.mp4';

        try {
            $this->encode($slides, $output, $durations, $transitionSeconds, $total, $this->background($brand), $audioPath, $motion);

            $binary = file_get_contents($output);

            if ($binary === false || mb_strlen($binary) < 1024) {
                throw new RuntimeException('ffmpeg je proizveo prazan video.');
            }

            $disk = (string) config('hub.media_disk', 'public');
            $path = sprintf('media/%s/%s/%s.mp4', $brand->slug, now()->format('Y/m'), Str::uuid());

            Storage::disk($disk)->put($path, $binary, 'public');

            return MediaAsset::query()->create([
                'brand_id' => $brand->id,
                'post_draft_id' => $draft?->id,
                'template_key' => self::TEMPLATE_KEY,
                'params' => [
                    'slides' => $slides->pluck('id')->all(),
                    'seconds_per_slide' => $secondsPerSlide,
                    'last_slide_seconds' => end($durations),
                    'slide_seconds' => $durations,
                    'transition_seconds' => $transitionSeconds,
                    'audio' => $audioPath === null ? null : basename($audioPath),
                    'motion' => $motion,
                ],
                'width' => self::WIDTH,
                'height' => self::HEIGHT,
                'duration_ms' => (int) round($total * 1000),
                // The first slide doubles as the cover: it is what a feed shows before playback.
                'poster_media_asset_id' => $slides->first()->id,
                'format' => 'mp4',
                'disk' => $disk,
                'path' => $path,
                'bytes' => mb_strlen($binary),
                'checksum' => hash('sha256', $binary),
            ]);
        } finally {
            @unlink($output);
        }
    }

    /**
     * How long each slide stays on screen, in order. Only the last one may differ.
     *
     * @return list<float>
     */
    private function durations(int $count, float $perSlide, ?float $lastSlideSeconds): array
    {
        $durations = array_fill(0, $count, $perSlide);

        if ($lastSlideSeconds !== null) {
            $durations[$count - 1] = max($perSlide, $lastSlideSeconds);
        }

        return $durations;
    }

    /**
     * @param  Collection<int, MediaAsset>  $slides
     * @param  list<float>  $durations
     */
    private function encode(
        Collection $slides,
        string $output,
        array $durations,
        float $transition,
        float $total,
        string $background,
        ?string $audioPath,
        bool $motion,
    ): void {
        $arguments = ['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'];

        foreach ($slides->values() as $i => $slide) {
            // zoompan turns one still frame into a whole clip itself; a looped input would multiply it.
            $arguments = $motion
                ? [...$arguments, '-i', $slide->absolutePath()]
                : [...$arguments, '-loop', '1', '-t', (string) $durations[$i], '-i', $slide->absolutePath()];
        }

        $audioInput = $slides->count();

        $arguments = $audioPath === null
            // A silent stereo track: some players and uploaders treat a video with no audio stream as broken.
            ? [...$arguments, '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100']
            // Loop a short track, cut a long one; either way the video decides the length.
            : [...$arguments, '-stream_loop', '-1', '-i', $audioPath];

        $graph = $this->filter($durations, $transition, $background, $motion);

        if ($audioPath !== null) {
            $graph .= ';'.$this->audioFilter($audioInput, $total);
        }

        $arguments = [...$arguments,
            '-filter_complex', $graph,
            '-map', '[out]',
            '-map', $audioPath === null ? $audioInput.':a' : '[aout]',
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '21',
            '-pix_fmt', 'yuv420p',
            '-r', (string) self::FPS,
            '-c:a', 'aac',
            '-b:a', '128k',
            '-ar', '44100',
            '-shortest',
            '-movflags', '+faststart',
            $output,
        ];

        $process = new Process($arguments, timeout: (int) config('hub.render.video_timeout', 300));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg nije uspio: '.mb_substr($process->getErrorOutput() ?: $process->getOutput(), 0, 500));
        }
    }

    /**
     * Scale every slide into the vertical frame, then crossfade them one into the next.
     *
     * @param  list<float>  $durations
     */
    private function filter(array $durations, float $transition, string $background, bool $motion): string
    {
        $count = count($durations);
        $parts = [];

        for ($i = 0; $i < $count; $i++) {
            $parts[] = $motion
                ? $this->movingSlide($i, $durations[$i], $background)
                : sprintf(
                    '[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease,'
                    .'pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=%s,setsar=1,fps=%d,format=yuv420p[v%d]',
                    $i, self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT, $background, self::FPS, $i,
                );
        }

        if ($count === 1) {
            return implode(';', [...$parts, '[v0]null[out]']);
        }

        if ($transition === 0.0) {
            $inputs = implode('', array_map(fn (int $i): string => "[v{$i}]", range(0, $count - 1)));

            return implode(';', [...$parts, $inputs.'concat=n='.$count.':v=1:a=0[out]']);
        }

        $current = '[v0]';
        $offset = 0.0;

        for ($i = 1; $i < $count; $i++) {
            // Each transition starts one slide-length (minus the overlap) after the previous one.
            $offset += $durations[$i - 1] - $transition;
            $label = $i === $count - 1 ? '[out]' : "[x{$i}]";

            $parts[] = sprintf('%s[v%d]xfade=transition=fade:duration=%.2f:offset=%.2f%s', $current, $i, $transition, $offset, $label);
            $current = $label;
        }

        return implode(';', $parts);
    }

    /**
     * A slow, centred Ken Burns: even slides push in, odd ones pull out, so consecutive slides
     * don't all drift the same way.
     *
     * zoompan rounds its crop window to whole pixels; working on a 2x upscale keeps that rounding
     * below what the eye sees as jitter.
     */
    private function movingSlide(int $index, float $perSlide, string $background): string
    {
        $frames = (int) round($perSlide * self::FPS);
        $zoom = $index % 2 === 0
            ? sprintf('1+%.3f*on/%d', self::ZOOM, $frames)
            : sprintf('%.3f-%.3f*on/%d', 1 + self::ZOOM, self::ZOOM, $frames);

        return sprintf(
            '[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease,'
            .'pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=%s,setsar=1,'
            ."zoompan=z='%s':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=%d:s=%dx%d:fps=%d,"
            .'setsar=1,format=yuv420p[v%d]',
            $index, self::WIDTH * 2, self::HEIGHT * 2, self::WIDTH * 2, self::HEIGHT * 2, $background,
            $zoom, $frames, self::WIDTH, self::HEIGHT, self::FPS, $index,
        );
    }

    /**
     * Cut the track to the video, level it to the loudness the feeds normalise to anyway, and fade
     * it out rather than letting the last beat stop mid-bar.
     */
    private function audioFilter(int $input, float $total): string
    {
        $fadeOut = min(self::AUDIO_FADE_OUT_SECONDS, $total / 3);

        return sprintf(
            '[%d:a]atrim=0:%.3f,asetpts=N/SR/TB,loudnorm=I=-16:TP=-1.5:LRA=11,aresample=44100,'
            .'afade=t=in:st=0:d=0.3,afade=t=out:st=%.3f:d=%.3f,aformat=channel_layouts=stereo[aout]',
            $input, $total, max(0.0, $total - $fadeOut), $fadeOut,
        );
    }

    private function background(Brand $brand): string
    {
        $color = (string) ($brand->colors['background'] ?? '#ffffff');

        return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? '0x'.mb_substr($color, 1) : 'white';
    }
}
