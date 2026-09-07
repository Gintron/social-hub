<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builds valid Social Feed v1 payloads for tests.
 */
final class FeedPayload
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function item(string $id = 'job:1', array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'kind' => 'job',
            'title' => 'Konobar/ica',
            'subtitle' => 'Hotel Adriatic',
            'body_text' => "• Posluživanje\n• Rad u smjenama\n\nNačin prijave:\nposao@example.test",
            'facts' => [['label' => 'LOKACIJA', 'value' => 'SPLIT'], ['label' => 'SATNICA', 'value' => '7.00 - 8.00 €/H']],
            'badges' => ['Sezonski posao', 'Smještaj'],
            'price' => ['current_cents' => 700, 'old_cents' => null, 'discount_pct' => null, 'currency' => 'EUR', 'unit_label' => '€/H'],
            'cta' => ['label' => 'Prijavi se', 'url' => 'https://example.test/posao/konobar-1'],
            'url' => 'https://example.test/posao/konobar-1',
            'images' => [['url' => 'https://example.test/images/1.webp', 'role' => 'primary', 'alt' => 'Konobar/ica']],
            'priority' => 4,
            'tags' => ['konobar', 'split'],
            'published_at' => '2026-09-05T07:10:00Z',
            'expires_at' => '2026-10-05T00:00:00Z',
            'updated_at' => '2026-09-05T07:10:00Z',
            'raw' => ['offer_id' => 4, 'tier' => 'premium'],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function page(array $items, ?string $nextCursor = null, string $brand = 'studentski-poslovi'): array
    {
        return [
            'version' => '1',
            'brand' => $brand,
            'items' => $items,
            'next_cursor' => $nextCursor,
        ];
    }
}
