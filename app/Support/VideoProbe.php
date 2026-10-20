<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * What a finished video or picture says about itself: its size and, for a video, how long it runs. Nothing is guessed from
 * the name; ffprobe (which comes with the ffmpeg the renders already use) is asked.
 */
final class VideoProbe
{
    /**
     * @return array{width: int, height: int, duration_ms: int|null}
     *
     * @throws RuntimeException When the file is not something ffprobe can read.
     */
    public function probe(string $path): array
    {
        $process = new Process([
            'ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height:format=duration', '-of', 'json', $path,
        ]);
        $process->setTimeout(60)->run();

        $data = json_decode($process->getOutput(), true);
        $stream = is_array($data) ? ($data['streams'][0] ?? null) : null;

        if (! $process->isSuccessful() || ! is_array($stream) || ! isset($stream['width'], $stream['height'])) {
            throw new RuntimeException('ffprobe ne može pročitati datoteku: '.mb_substr(mb_trim($process->getErrorOutput()) ?: $path, 0, 300));
        }

        $seconds = $data['format']['duration'] ?? null;

        return [
            'width' => (int) $stream['width'],
            'height' => (int) $stream['height'],
            'duration_ms' => is_numeric($seconds) ? (int) round((float) $seconds * 1000) : null,
        ];
    }

    /**
     * A still image as the JPEG a feed and Instagram take, 1080 px wide: what a cover is made of.
     *
     * @throws RuntimeException
     */
    public function toJpeg(string $from, string $to): void
    {
        $process = new Process(['ffmpeg', '-y', '-hide_banner', '-loglevel', 'error', '-i', $from, '-vf', 'scale=1080:-2', '-q:v', '2', '-frames:v', '1', $to]);
        $process->setTimeout(60)->run();

        if (! $process->isSuccessful() || ! is_file($to) || filesize($to) < 100) {
            throw new RuntimeException('ffmpeg nije uspio napraviti naslovnu sliku: '.mb_substr(mb_trim($process->getErrorOutput()), 0, 300));
        }
    }
}
