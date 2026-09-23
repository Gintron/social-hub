<?php

declare(strict_types=1);

namespace App\Drafting;

use App\Enums\ContentKind;
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
     * `old` is the price before the discount, when the item has one — struck through next to the
     * figure, it is what makes "84,99 €" read as a bargain.
     *
     * @return array{value: string, label: string|null, fact: string|null, old: string|null}|null
     */
    public static function figure(ContentItem $item): ?array
    {
        // A comparison has no price of its own: its figure is the first row, and whose it is.
        if ($item->kind === ContentKind::Comparison) {
            $first = ($item->facts ?? [])[0] ?? null;

            return $first === null ? null : [
                'value' => (string) $first['value'],
                'label' => (string) $first['label'],
                'fact' => (string) $first['label'],
                'old' => null,
            ];
        }

        $price = $item->price ?? [];
        $amount = self::currentAmount($item);

        if ($amount === null) {
            return null;
        }

        foreach ($item->facts ?? [] as $fact) {
            $value = (string) ($fact['value'] ?? '');

            // The site's wording wins only when it says more than the number. A fact that merely
            // repeats it ("NAJNIŽA U 30 DANA: 1,89 €") is a different claim, not a label for it.
            if (self::mentions($value, $amount) && ! self::isBareAmount($value)) {
                return ['value' => $value, 'label' => (string) $fact['label'], 'fact' => (string) $fact['label'], 'old' => null];
            }
        }

        $unit = (string) ($price['unit_label'] ?? '');
        $old = (int) ($price['old_cents'] ?? 0);
        $discount = $price['discount_pct'] ?? null;

        return [
            'value' => self::amount($amount, $unit),
            'label' => filled($discount) ? "−{$discount} %" : null,
            'fact' => null,
            'old' => $old > (int) $price['current_cents'] ? self::amount($old / 100, $unit) : null,
        ];
    }

    /**
     * Facts other than the one the figure came from, values tidied for reading aloud.
     *
     * @return list<string>
     */
    public static function points(ContentItem $item, int $limit = 2): array
    {
        // The rows after the first keep their names: "11,98 €/kg" alone reads as a second price
        // of the first shop.
        if ($item->kind === ContentKind::Comparison) {
            return array_slice(array_map(
                fn (array $fact): string => $fact['label'].' '.$fact['value'],
                array_slice($item->facts ?? [], 1),
            ), 0, $limit);
        }

        $skip = self::figure($item)['fact'] ?? null;
        $points = [];

        foreach ($item->facts ?? [] as $fact) {
            if ($skip !== null && ($fact['label'] ?? null) === $skip) {
                continue;
            }

            $value = self::tidy((string) ($fact['value'] ?? ''));

            // A point is shown without its label, and "1,89 €" without "najniža u 30 dana" says
            // nothing — or worse, reads as a second price.
            if ($value === '' || self::isBareAmount($value)) {
                continue;
            }

            $points[] = $value;

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

    private static function currentAmount(ContentItem $item): ?float
    {
        $cents = ($item->price ?? [])['current_cents'] ?? null;

        return filled($cents) ? ((int) $cents) / 100 : null;
    }

    private static function amount(float $amount, string $unit): string
    {
        $formatted = number_format($amount, 2, ',', '.');

        return match (true) {
            str_contains($unit, '€') => "{$formatted} {$unit}",
            $unit !== '' => "{$formatted} € / {$unit}",
            default => "{$formatted} €",
        };
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
            // Whole euros ("1") must not be found at the start of another amount ("1,89 €").
            if (preg_match('/(?<![\d.,])'.preg_quote($variant, '/').'(?![.,]?\d)/u', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Just a sum of money, with no unit or range to it: "1,89 €", "1.200 €".
     */
    private static function isBareAmount(string $value): bool
    {
        return preg_match('/^\s*\d+(?:[.,]\d+)*\s*€\s*$/u', $value) === 1;
    }
}
