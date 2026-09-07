<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
final class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'platform' => Platform::FacebookPage,
            'name' => fake()->company().' Page',
            'external_id' => (string) fake()->unique()->numberBetween(100000000000, 999999999999),
            'access_token' => 'page-token-'.fake()->sha1(),
            'status' => AccountStatus::Active,
            'meta' => [],
        ];
    }

    public function instagram(): self
    {
        return $this->state(fn (): array => ['platform' => Platform::InstagramBusiness, 'name' => '@'.fake()->userName()]);
    }

    public function group(): self
    {
        return $this->state(fn (): array => ['platform' => Platform::FacebookGroup, 'name' => 'Studentski poslovi Zagreb', 'external_id' => 'https://www.facebook.com/groups/123', 'access_token' => null]);
    }
}
