<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
final class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'post_draft_id' => null,
            'template_key' => 'kinds/job-square',
            'params' => [],
            'width' => 1080,
            'height' => 1080,
            'format' => 'jpg',
            'disk' => 'public',
            'path' => 'media/'.fake()->uuid().'.jpg',
            'bytes' => 180_000,
            'checksum' => fake()->sha256(),
        ];
    }

    public function portrait(): self
    {
        return $this->state(fn (): array => ['width' => 1080, 'height' => 1350, 'template_key' => 'kinds/job-portrait']);
    }

    public function story(): self
    {
        return $this->state(fn (): array => ['width' => 1080, 'height' => 1920]);
    }
}
