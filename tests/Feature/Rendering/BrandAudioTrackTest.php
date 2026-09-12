<?php

declare(strict_types=1);

namespace Tests\Feature\Rendering;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The one place a choice from the panel or CLI (`auto`, `none`, an index) becomes a file ffmpeg reads.
 */
final class BrandAudioTrackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::disk('public')->put('brands/audio/a.mp3', 'a');
        Storage::disk('public')->put('brands/audio/b.mp3', 'b');
    }

    public function test_none_and_an_empty_library_both_mean_silence(): void
    {
        $this->assertNull($this->brand()->audioTrackPath('none'));
        $this->assertNull(Brand::factory()->create(['audio_tracks' => null])->audioTrackPath('auto', 7));
    }

    public function test_auto_rotates_by_seed_so_posts_vary_but_a_rerender_keeps_its_track(): void
    {
        $brand = $this->brand();

        $this->assertStringEndsWith('a.mp3', (string) $brand->audioTrackPath('auto', 0));
        $this->assertStringEndsWith('b.mp3', (string) $brand->audioTrackPath('auto', 1));
        $this->assertStringEndsWith('a.mp3', (string) $brand->audioTrackPath('auto', 2));
        $this->assertSame($brand->audioTrackPath('auto', 41), $brand->audioTrackPath('auto', 41));
    }

    public function test_an_index_picks_that_track_and_an_unknown_one_is_silence(): void
    {
        $brand = $this->brand();

        $this->assertStringEndsWith('b.mp3', (string) $brand->audioTrackPath('1'));
        $this->assertNull($brand->audioTrackPath('9'));
        $this->assertNull($brand->audioTrackPath('loud'));
    }

    public function test_a_file_that_is_gone_silences_the_video_instead_of_failing_the_render(): void
    {
        Storage::disk('public')->delete('brands/audio/a.mp3');

        $this->assertNull($this->brand()->audioTrackPath('0'));
    }

    public function test_repeater_keys_and_half_filled_rows_do_not_shift_the_order(): void
    {
        // Filament's repeater keys rows by uuid; a row without a file is not a track.
        $brand = Brand::factory()->create(['audio_tracks' => [
            'c1f0' => ['title' => 'Prazno', 'path' => null],
            '9a2b' => ['title' => 'Jutro', 'path' => 'brands/audio/b.mp3', 'license' => 'Pixabay'],
        ]]);

        $this->assertSame([['path' => 'brands/audio/b.mp3', 'title' => 'Jutro', 'license' => 'Pixabay']], $brand->audioTracks());
        $this->assertStringEndsWith('b.mp3', (string) $brand->audioTrackPath('0'));
    }

    private function brand(): Brand
    {
        return Brand::factory()->create(['audio_tracks' => [
            ['title' => 'A', 'path' => 'brands/audio/a.mp3', 'license' => null],
            ['title' => 'B', 'path' => 'brands/audio/b.mp3', 'license' => null],
        ]]);
    }
}
