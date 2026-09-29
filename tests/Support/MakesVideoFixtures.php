<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Voiceover\NarrationClip;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Real slides, real tones and ffmpeg's own measurements — what a video test needs to say something true
 * about the file that came out.
 */
trait MakesVideoFixtures
{
    protected function slide(Brand $brand): MediaAsset
    {
        Storage::disk('public')->makeDirectory('slides');
        $path = Storage::disk('public')->path('slides/'.uniqid('slide-').'.jpg');

        $image = imagecreatetruecolor(1080, 1920);
        imagefill($image, 0, 0, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $stripe = imagecolorallocate($image, 0, 0, 0);

        for ($x = 0; $x < 1080; $x += 80) {
            imagefilledrectangle($image, $x, 0, $x + 39, 1919, $stripe);
        }

        imagejpeg($image, $path, 85);
        imagedestroy($image);

        return MediaAsset::factory()->for($brand)->create([
            'width' => 1080,
            'height' => 1920,
            'format' => 'jpg',
            'disk' => 'public',
            'path' => 'slides/'.basename($path),
            'bytes' => filesize($path) ?: null,
        ]);
    }

    /**
     * A spoken line as the renderer receives it: a tone of this length and pitch, on disk.
     */
    protected function clip(float $seconds, int $frequency = 440, float $gainDb = 0.0): NarrationClip
    {
        Storage::disk('local')->makeDirectory('voiceovers/test');
        $path = Storage::disk('local')->path('voiceovers/test/'.uniqid('clip-').'.mp3');
        file_put_contents($path, FakeSpeech::mp3($seconds, $frequency));

        return new NarrationClip($path, $seconds, $gainDb, 'test', 0, 10);
    }

    protected function toneTrack(int $seconds, int $frequency = 220): string
    {
        Storage::disk('public')->makeDirectory('brands/audio');
        $path = Storage::disk('public')->path('brands/audio/'.uniqid('tone-').'.m4a');

        (new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', "sine=frequency={$frequency}:duration={$seconds}", '-c:a', 'aac', $path]))->mustRun();

        return $path;
    }

    protected function probe(string $file): string
    {
        $process = new Process([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-show_entries', 'stream=codec_name,codec_type,duration',
            '-of', 'default=noprint_wrappers=1',
            $file,
        ]);
        $process->run();

        return $process->getOutput();
    }

    /**
     * Length of one stream, from ffprobe.
     */
    protected function streamSeconds(string $file, string $type): float
    {
        $process = new Process(['ffprobe', '-v', 'error', '-select_streams', $type === 'audio' ? 'a:0' : 'v:0', '-show_entries', 'stream=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $file]);
        $process->run();

        return (float) mb_trim($process->getOutput());
    }

    /**
     * Mean level in dB of the audio between two moments, optionally of one pitch only (a narrow band
     * around $band Hz, to tell a tone of the voice from a tone of the music).
     */
    protected function level(string $file, float $from, float $to, ?int $band = null): float
    {
        // Two stages: one leaves a tone an octave away only about 9 dB down, which a quiet bed cannot survive.
        $filter = ($band === null ? '' : "bandpass=f={$band}:width_type=h:w=60,bandpass=f={$band}:width_type=h:w=60,").'volumedetect';
        $process = new Process([
            'ffmpeg', '-hide_banner', '-ss', (string) $from, '-t', (string) ($to - $from), '-i', $file,
            '-map', '0:a', '-af', $filter, '-f', 'null', '-',
        ]);
        $process->run();

        preg_match('/mean_volume:\s*(-?[\d.]+|-inf) dB/', $process->getErrorOutput(), $matches);

        return ($matches[1] ?? '-inf') === '-inf' ? -INF : (float) $matches[1];
    }
}
