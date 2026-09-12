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
 * A still, silent slideshow is what these feeds push least, so by default every slide drifts in a
 * slow zoom and a brand track plays underneath. TikTok's API cannot attach a sound from TikTok's
 * own library; whatever plays is mixed in here.
 */
final class VideoRenderer
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

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

    /**
     * @param  Collection<int, MediaAsset>  $slides  Rendered images, in the order they should play.
     * @param  string|null  $audioPath  Absolute path to a track to play underneath; null keeps a silent track.
     */
    public function slideshow(
        Brand $brand,
        Collection $slides,
        ?PostDraft $draft = null,
        float $secondsPerSlide = 3.0,
        float $transitionSeconds = 0.6,
        ?string $audioPath = null,
        bool $motion = true,
    ): MediaAsset {
        if ($slides->isEmpty()) {
            throw new RuntimeException('Video treba barem jedan slajd.');
        }

        if ($audioPath !== null && ! is_file($audioPath)) {
            throw new RuntimeException("Zvučni zapis ne postoji: {$audioPath}");
        }

        $secondsPerSlide = max(1.5, $secondsPerSlide);
        $transitionSeconds = min($transitionSeconds, $secondsPerSlide / 2);

        $count = $slides->count();
        $total = $count * $secondsPerSlide - ($count - 1) * $transitionSeconds;

        // A single slide would otherwise produce a 3-second clip only by accident; make the floor explicit.
        if ($total < self::MIN_TOTAL_SECONDS) {
            $secondsPerSlide += (self::MIN_TOTAL_SECONDS - $total) / $count + 0.1;
            $total = $count * $secondsPerSlide - ($count - 1) * $transitionSeconds;
        }

        $output = tempnam(sys_get_temp_dir(), 'hub-video-').'.mp4';

        try {
            $this->encode($slides, $output, $secondsPerSlide, $transitionSeconds, $total, $this->background($brand), $audioPath, $motion);

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
                'template_key' => 'video/slideshow',
                'params' => [
                    'slides' => $slides->pluck('id')->all(),
                    'seconds_per_slide' => $secondsPerSlide,
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
     * @param  Collection<int, MediaAsset>  $slides
     */
    private function encode(
        Collection $slides,
        string $output,
        float $perSlide,
        float $transition,
        float $total,
        string $background,
        ?string $audioPath,
        bool $motion,
    ): void {
        $arguments = ['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'];

        foreach ($slides as $slide) {
            // zoompan turns one still frame into a whole clip itself; a looped input would multiply it.
            $arguments = $motion
                ? [...$arguments, '-i', $slide->absolutePath()]
                : [...$arguments, '-loop', '1', '-t', (string) $perSlide, '-i', $slide->absolutePath()];
        }

        $audioInput = $slides->count();

        $arguments = $audioPath === null
            // A silent stereo track: some players and uploaders treat a video with no audio stream as broken.
            ? [...$arguments, '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100']
            // Loop a short track, cut a long one; either way the video decides the length.
            : [...$arguments, '-stream_loop', '-1', '-i', $audioPath];

        $graph = $this->filter($slides->count(), $perSlide, $transition, $background, $motion);

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
     */
    private function filter(int $count, float $perSlide, float $transition, string $background, bool $motion): string
    {
        $parts = [];

        for ($i = 0; $i < $count; $i++) {
            $parts[] = $motion
                ? $this->movingSlide($i, $perSlide, $background)
                : sprintf(
                    '[%d:v]scale=%d:%d:force_original_aspect_ratio=decrease,'
                    .'pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=%s,setsar=1,fps=%d,format=yuv420p[v%d]',
                    $i, self::WIDTH, self::HEIGHT, self::WIDTH, self::HEIGHT, $background, self::FPS, $i,
                );
        }

        if ($count === 1) {
            return implode(';', [...$parts, '[v0]null[out]']);
        }

        $current = '[v0]';

        for ($i = 1; $i < $count; $i++) {
            // Each transition starts one slide-length (minus the overlap) after the previous one.
            $offset = $i * ($perSlide - $transition);
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
