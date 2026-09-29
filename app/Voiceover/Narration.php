<?php

declare(strict_types=1);

namespace App\Voiceover;

/**
 * A video's voice: one clip per slide (null where the slide is left to the music), the script they
 * were spoken from, and how far below the voice the music plays.
 *
 * The renderer places the clips; nothing here knows about ffmpeg.
 */
final readonly class Narration
{
    /**
     * @param  list<NarrationClip|null>  $clips  One per slide, in the order of the video.
     */
    public function __construct(
        public array $clips,
        public Script $script,
        public float $musicGainDb,
        public string $voiceId,
        public string $model,
    ) {}

    public function characters(): int
    {
        return array_sum(array_map(fn (?NarrationClip $clip): int => $clip?->characters ?? 0, $this->clips));
    }

    /**
     * What is kept on the video asset: enough to see what was said and by whom, and to edit it.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'status' => 'ok',
            'voice_id' => $this->voiceId,
            'model' => $this->model,
            'characters' => $this->characters(),
            'script' => $this->script->toArray(),
            'clips' => array_map(fn (?NarrationClip $clip): ?array => $clip === null ? null : [
                'voiceover_id' => $clip->voiceoverId,
                'seconds' => round($clip->seconds, 2),
            ], $this->clips),
        ];
    }
}
