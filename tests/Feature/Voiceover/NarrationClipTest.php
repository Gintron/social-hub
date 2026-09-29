<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Models\Voiceover;
use App\Voiceover\NarrationClip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each line is spoken by a request of its own and comes back at its own level; this is what brings
 * them to one.
 */
final class NarrationClipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{float|null, float|null, float}>
     */
    public static function levels(): array
    {
        return [
            'quiet speech with room for the gain' => [-20.0, -6.0, 6.0],
            'a clip already at the target' => [-14.0, -3.0, 0.0],
            // Needs +8, but its peak is only 2.5 dB under the ceiling and the limiter is asked for 4 dB at most.
            'a peaky clip is left a little under the target' => [-22.0, -4.0, 6.5],
            'a clip louder than the target comes down' => [-10.0, -1.0, -4.0],
            'a clip that could not be measured is left as it is' => [null, null, 0.0],
            'a peak that could not be measured does not stop the gain' => [-18.0, null, 4.0],
            'a clip near silence is not amplified without limit' => [-60.0, -40.0, 18.0],
            'a clip at full scale is not turned down without limit' => [0.0, 0.0, -12.0],
        ];
    }

    #[DataProvider('levels')]
    public function test_a_clip_is_brought_to_the_target_without_asking_the_limiter_for_too_much(?float $lufs, ?float $peak, float $gain): void
    {
        $clip = NarrationClip::from(Voiceover::factory()->create(['loudness_lufs' => $lufs, 'true_peak_db' => $peak, 'duration_ms' => 2500, 'text' => 'Bok.']));

        $this->assertEqualsWithDelta($gain, $clip->gainDb, 0.05);
        $this->assertSame(2.5, $clip->seconds);
        $this->assertSame('Bok.', $clip->spoken);
    }
}
