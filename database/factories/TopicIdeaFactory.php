<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TopicStatus;
use App\Models\Brand;
use App\Models\TopicIdea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TopicIdea>
 */
final class TopicIdeaFactory extends Factory
{
    protected $model = TopicIdea::class;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory()->persona(),
            'for_date' => now('Europe/Zagreb')->toDateString(),
            'title' => fake()->sentence(5),
            'hook' => fake()->sentence(8),
            'angle' => fake()->paragraph(),
            'reason' => fake()->sentence(),
            'status' => TopicStatus::Proposed,
        ];
    }

    public function scripted(): self
    {
        return $this->state(fn (): array => [
            'status' => TopicStatus::Scripted,
            'chosen_at' => now(),
            'scripted_at' => now(),
            'script' => [
                'title' => 'Wait 24 hours before you buy',
                'lines' => [
                    ['text' => 'Stop. Before you buy that, wait a day.', 'shot' => 'talking', 'visual' => 'raises a hand, smiling'],
                    ['text' => 'Most things you want tonight, you will not want tomorrow.', 'shot' => 'broll', 'visual' => 'a shopping cart full of boxes in soft evening light'],
                    ['text' => 'Try it once this week and tell me what you skipped.', 'shot' => 'talking', 'visual' => 'leans in, friendly'],
                ],
                'caption' => "The 24-hour rule.\nTry it once this week.",
                'hashtags' => '#money #habits #shorts',
            ],
        ]);
    }
}
