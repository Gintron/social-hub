<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuthType;
use App\Enums\SourceType;
use App\Models\Brand;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
final class SourceFactory extends Factory
{
    protected $model = Source::class;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'name' => fake()->unique()->slug(2),
            'type' => SourceType::SocialFeedV1,
            'base_url' => 'https://'.fake()->domainName().'/api/social-feed',
            'auth_type' => AuthType::Bearer,
            'secret' => 'test-token',
            'config' => null,
            'enabled' => true,
        ];
    }
}
