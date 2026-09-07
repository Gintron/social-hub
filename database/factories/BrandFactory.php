<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brand>
 */
final class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => str($name)->slug()->toString(),
            'name' => $name,
            'site_url' => 'https://'.fake()->domainName(),
            'colors' => ['primary' => '#1d4ed8', 'accent' => '#f59e0b', 'text' => '#111827', 'background' => '#ffffff'],
            'voice' => ['tone' => 'prijateljski, jasan', 'language' => 'hr', 'hashtags' => []],
            'posting_windows' => [['from' => '08:00', 'to' => '20:00']],
            'timezone' => 'Europe/Zagreb',
        ];
    }
}
