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

    /**
     * A brand that is an AI character, described enough to write topics from.
     *
     * @param  array<string, mixed>  $persona
     */
    public function persona(array $persona = []): self
    {
        return $this->state(fn (): array => ['persona' => [
            'enabled' => true,
            'language' => 'en',
            'name' => 'Mia',
            'description' => 'A calm money coach in her thirties who explains personal finance without jargon.',
            'goal' => 'Help young people spend less on impulse and save their first emergency fund.',
            'audience' => 'People aged 18-30 with their first job',
            'tone' => 'warm, direct, a little funny',
            'appearance' => 'Woman with short dark hair, round glasses, mustard sweater',
            'ambience' => 'A bright small kitchen with plants on the window sill',
            'avoid' => 'Crypto, stock picks, debt collection',
            'topics_per_day' => 3,
            'suggest_at' => '07:00',
            ...$persona,
        ]]);
    }
}
