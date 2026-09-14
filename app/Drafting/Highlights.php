<?php

declare(strict_types=1);

namespace App\Drafting;

use App\Models\ContentItem;

/**
 * The part of an item worth shouting: the number a reader decides on (the pay, the price) and the
 * one or two facts next to it. Shared by the hook slide and the captions, so the first line of the
 * text and the first second of the video say the same thing.
 *
 * Generic on purpose — it reads `price` and `facts`, never a site's own field names.
 */
final class Highlights
{
    /**
     * The headline number, taken from the item's own wording where the site already wrote it
     * ("7.00 - 8.00 €/H" carries a range the price field cannot), otherwise formatted from `price`.
     *
     * @return array{value: string, label: string|null, fact: string|null}|null
     */
    public static function figure(ContentItem $item): ?array
    {
        $price = $item->price ?? [];

        if (! filled($price['current_cents'] ?? null)) {
            return null;
        }

        $amount = ((int) $price['current_cents']) / 100;

        foreach ($item->facts ?? [] as $fact) {
            $value = (string) ($fact['value'] ?? '');

            if (self::mentions($value, $amount)) {
                return ['value' => $value, 'label' => (string) $fact['label'], 'fact' => (string) $fact['label']];
            }
        }

        $formatted = number_format($amount, 2, ',', '.');
        $unit = (string) ($price['unit_label'] ?? '');
        $value = match (true) {
            str_contains($unit, '€') => "{$formatted} {$unit}",
            $unit !== '' => "{$formatted} € / {$unit}",
            default => "{$formatted} €",
        };

        $discount = $price['discount_pct'] ?? null;

        return ['value' => $value, 'label' => filled($discount) ? "−{$discount} %" : null, 'fact' => null];
    }

    /**
     * Facts other than the one the figure came from, values tidied for reading aloud.
     *
     * @return list<string>
     */
    public static function points(ContentItem $item, int $limit = 2): array
    {
        $skip = self::figure($item)['fact'] ?? null;
        $points = [];

        foreach ($item->facts ?? [] as $fact) {
            if ($skip !== null && ($fact['label'] ?? null) === $skip) {
                continue;
            }

            $value = self::tidy((string) ($fact['value'] ?? ''));

            if ($value !== '') {
                $points[] = $value;
            }

            if (count($points) >= $limit) {
                break;
            }
        }

        return $points;
    }

    /**
     * Feeds shout ("LOVRAN", "RAD OD KUĆE"); a caption should not. Values with digits are left
     * alone — "7.50 €/H" is a figure, not a word.
     */
    public static function tidy(string $value): string
    {
        $value = mb_trim($value);

        if ($value === '' || preg_match('/\d/u', $value) === 1 || preg_match('/\p{L}/u', $value) !== 1) {
            return $value;
        }

        if (mb_strtoupper($value) !== $value) {
            return $value;
        }

        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE);
    }

    private static function mentions(string $value, float $amount): bool
    {
        $variants = [
            number_format($amount, 2, '.', ''),
            number_format($amount, 2, ',', ''),
        ];

        if ($amount === floor($amount)) {
            $variants[] = (string) (int) $amount;
        }

        foreach ($variants as $variant) {
            if (preg_match('/(?<![\d.,])'.preg_quote($variant, '/').'(?![\d])/u', $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
