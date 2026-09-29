<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * A stand-in for ElevenLabs that answers with real MP3 audio — a tone as long as the text would take to
 * say — so the code after the API (ffprobe, loudness, the mix) runs on files ffmpeg actually decodes.
 */
final class FakeSpeech
{
    /** Roughly how many characters a Croatian narrator gets through in a second. */
    public const CHARACTERS_PER_SECOND = 14.0;

    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * A mono 44.1 kHz MP3 tone, the shape ElevenLabs returns.
     */
    public static function mp3(float $seconds = 1.5, int $frequency = 220): string
    {
        $key = sprintf('%.2f-%d', $seconds, $frequency);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $file = tempnam(sys_get_temp_dir(), 'speech-').'.mp3';

        try {
            (new Process([
                'ffmpeg', '-y', '-v', 'error', '-f', 'lavfi',
                '-i', sprintf('sine=frequency=%d:duration=%.3f:sample_rate=44100', $frequency, $seconds),
                // ffmpeg's sine source is at 1/8 of full scale; this brings it to a speech-like level (about -9 dBFS RMS).
                '-af', 'volume=4', '-ac', '1', '-c:a', 'libmp3lame', '-b:a', '128k', $file,
            ]))->mustRun();

            return self::$cache[$key] = (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Route every text-to-speech request to a clip whose length follows the text.
     *
     * @param  list<array<string, mixed>>  $requests  Filled with the body of every request, in order.
     */
    public static function fake(array &$requests = [], float $minimumSeconds = 0.8): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/*' => function (Request $request) use (&$requests, $minimumSeconds) {
                $requests[] = $request->data();
                $text = (string) ($request->data()['text'] ?? '');

                return Http::response(
                    self::mp3(max($minimumSeconds, round(mb_strlen($text) / self::CHARACTERS_PER_SECOND, 1))),
                    200,
                    ['request-id' => 'req-'.mb_substr(md5($text), 0, 12), 'character-cost' => (string) mb_strlen($text)],
                );
            },
        ]);
    }
}
