<?php

declare(strict_types=1);

namespace App\Voiceover;

use App\Models\Voiceover;

/**
 * One spoken line, ready to be placed over a slide.
 */
final readonly class NarrationClip
{
    /**
     * The level every clip is brought to. Feeds do not normalise video the way music services do, so a
     * quiet video is a weak one: −14 LUFS is where short-form video is usually mixed.
     */
    public const TARGET_LUFS = -14.0;

    /** Headroom a clip keeps below full scale, in dBTP. */
    private const PEAK_CEILING = -1.5;

    /**
     * How far past that ceiling a clip may be brought to reach the target. The peaks of speech are far
     * above its loudness, so a clip at −14 LUFS overshoots the ceiling by a few dB; the limiter that ends
     * the mix (VideoRenderer) shaves those peaks, and this is the most it is asked to shave.
     */
    private const LIMITER_DEPTH = 4.0;

    public function __construct(
        /** Absolute path to the audio. */
        public string $path,
        public float $seconds,
        /** Gain that brings this clip to TARGET_LUFS; each clip is spoken by a request of its own and comes back at its own level. */
        public float $gainDb,
        /** What was sent to the voice, spelled out. */
        public string $spoken,
        public int $voiceoverId,
        public int $characters,
    ) {}

    public static function from(Voiceover $voiceover): self
    {
        $gain = $voiceover->loudness_lufs === null ? 0.0 : self::TARGET_LUFS - $voiceover->loudness_lufs;

        // A clip with a very dynamic delivery is left quieter rather than pressed hard against the limiter.
        if ($voiceover->true_peak_db !== null) {
            $gain = min($gain, self::PEAK_CEILING - $voiceover->true_peak_db + self::LIMITER_DEPTH);
        }

        return new self(
            path: $voiceover->absolutePath(),
            seconds: $voiceover->durationSeconds(),
            gainDb: round(max(-12.0, min(18.0, $gain)), 1),
            spoken: $voiceover->text,
            voiceoverId: $voiceover->id,
            characters: $voiceover->characters,
        );
    }
}
