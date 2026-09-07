<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentKind;
use App\Models\ContentItem;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentItem>
 */
final class ContentItemFactory extends Factory
{
    protected $model = ContentItem::class;

    public function definition(): array
    {
        $source = Source::factory();

        return [
            'source_id' => $source,
            'brand_id' => fn (array $attributes): int => Source::query()->findOrFail($attributes['source_id'])->brand_id,
            'external_id' => 'job:'.fake()->unique()->numberBetween(1, 999999),
            'kind' => ContentKind::Job,
            'title' => fake()->jobTitle(),
            'subtitle' => fake()->company(),
            'body_text' => '• '.fake()->sentence()."\n• ".fake()->sentence(),
            'facts' => [['label' => 'LOKACIJA', 'value' => 'ZAGREB'], ['label' => 'SATNICA', 'value' => '7.00 €/H']],
            'badges' => ['Sezonski posao'],
            'price' => ['current_cents' => 700, 'old_cents' => null, 'discount_pct' => null, 'currency' => 'EUR', 'unit_label' => '€/H'],
            'cta' => ['label' => 'Prijavi se', 'url' => 'https://example.test/posao/1'],
            'url' => 'https://example.test/posao/1',
            'images' => [['url' => 'https://example.test/images/1.jpg', 'role' => 'primary', 'alt' => null]],
            'priority' => 3,
            'tags' => ['zagreb'],
            'raw' => ['offer_id' => 3],
            'published_at' => now()->subHour(),
            'expires_at' => now()->addDays(14),
            'source_updated_at' => now()->subHour(),
            'checksum' => fake()->sha256(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
