<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Voiceover;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voiceover>
 */
final class VoiceoverFactory extends Factory
{
    protected $model = Voiceover::class;

    public function definition(): array
    {
        $text = fake()->sentence();

        return [
            'brand_id' => null,
            'hash' => hash('sha256', $text.fake()->uuid()),
            'voice_id' => 'voice-test',
            'model' => 'eleven_multilingual_v2',
            'text' => $text,
            'settings' => ['stability' => 0.55],
            'characters' => mb_strlen($text),
            'duration_ms' => 2500,
            'loudness_lufs' => -20.0,
            'true_peak_db' => -4.0,
            'disk' => 'local',
            'path' => 'voiceovers/test/'.fake()->uuid().'.mp3',
            'bytes' => 40_000,
            'request_id' => null,
        ];
    }
}
