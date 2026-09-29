<?php

declare(strict_types=1);

namespace App\Voiceover;

use Symfony\Component\Process\Process;

/**
 * What ffmpeg can tell about a clip: how long it is and how loud. Both are needed at render time —
 * the first to give a slide the time its line takes, the second to bring every clip to the same level
 * even though each was spoken by a separate request.
 */
final class AudioProbe
{
    public function duration(string $path): float
    {
        $process = new Process([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ], timeout: 30);
        $process->run();

        $seconds = mb_trim($process->getOutput());

        if (! $process->isSuccessful() || ! is_numeric($seconds) || (float) $seconds <= 0) {
            throw new VoiceoverException('Trajanje zvučnog zapisa se ne može očitati: '.mb_substr($process->getErrorOutput(), 0, 200), 'probe_failed');
        }

        return (float) $seconds;
    }

    /**
     * Integrated loudness (LUFS) and true peak (dBTP), or null for a clip too quiet or short to measure.
     *
     * @return array{lufs: float, peak: float}|null
     */
    public function loudness(string $path): ?array
    {
        $process = new Process([
            'ffmpeg', '-hide_banner', '-nostats', '-i', $path,
            '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11:print_format=json', '-f', 'null', '-',
        ], timeout: 60);
        $process->run();

        if (! $process->isSuccessful() || preg_match_all('/\{[^{}]*"input_i"[^{}]*\}/s', $process->getErrorOutput(), $matches) < 1) {
            return null;
        }

        $data = json_decode((string) end($matches[0]), true);
        $lufs = is_array($data) ? ($data['input_i'] ?? null) : null;
        $peak = is_array($data) ? ($data['input_tp'] ?? null) : null;

        if (! is_numeric($lufs) || ! is_numeric($peak) || ! is_finite((float) $lufs) || ! is_finite((float) $peak)) {
            return null;
        }

        return ['lufs' => (float) $lufs, 'peak' => (float) $peak];
    }
}
