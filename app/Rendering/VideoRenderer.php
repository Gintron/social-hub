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
     * @param  Collection<int, MediaAsset>  $slides  Rendered images, in the order they should play.
     */
    public function slideshow(
        Brand $brand,
        Collection $slides,
        ?PostDraft $draft = null,
        float $secondsPerSlide = 3.0,
        float $transitionSeconds = 0.6,
    ): MediaAsset {
        if ($slides->isEmpty()) {
            throw new RuntimeException('Video treba barem jedan slajd.');
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
            $this->encode($slides, $output, $secondsPerSlide, $transitionSeconds, $this->background($brand));

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
    private function encode(Collection $slides, string $output, float $perSlide, float $transition, string $background): void
    {
        $arguments = ['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error'];

        foreach ($slides as $slide) {
            $arguments = [...$arguments, '-loop', '1', '-t', (string) $perSlide, '-i', $slide->absolutePath()];
        }

        // A silent stereo track: some players and uploaders treat a video with no audio stream as broken.
        $arguments = [...$arguments, '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100'];
        $arguments = [...$arguments, '-filter_complex', $this->filter($slides->count(), $perSlide, $transition, $background)];
        $arguments = [...$arguments,
            '-map', '[out]',
            '-map', $slides->count().':a',
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', '21',
            '-pix_fmt', 'yuv420p',
            '-r', (string) self::FPS,
            '-c:a', 'aac',
            '-b:a', '96k',
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
    private function filter(int $count, float $perSlide, float $transition, string $background): string
    {
        $parts = [];

        for ($i = 0; $i < $count; $i++) {
            $parts[] = sprintf(
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

    private function background(Brand $brand): string
    {
        $color = (string) ($brand->colors['background'] ?? '#ffffff');

        return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? '0x'.mb_substr($color, 1) : 'white';
    }
}
