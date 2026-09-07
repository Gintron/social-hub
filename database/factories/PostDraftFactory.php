<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActorType;
use App\Enums\DraftStatus;
use App\Models\Brand;
use App\Models\PostDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostDraft>
 */
final class PostDraftFactory extends Factory
{
    protected $model = PostDraft::class;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'kind' => PostDraft::KIND_SINGLE,
            'title' => fake()->sentence(4),
            'status' => DraftStatus::PendingApproval,
            'created_by_type' => ActorType::Human,
        ];
    }
}
