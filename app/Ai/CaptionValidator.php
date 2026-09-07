<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Schemas\CaptionSet;
use App\Models\ContentItem;

/**
 * The rules the model is told about, enforced in code afterwards.
 *
 * A model that is asked not to invent a price will usually not invent one, and "usually" is not a
 * property you want on a published post. Every amount and every link in the output has to be
 * traceable to the item it was written from; anything else is a violation and the caller falls back
 * to the deterministic caption.
 */
final class CaptionValidator
{
    /**
     * Amounts and percentages, the numbers that turn a wrong post into a false advertisement.
     */
    private const MONEY_PATTERNS = [
        '/(\d[\d\s.,]*)\s*(?:€|eur\b|kn\b|kuna\b)/iu',
        '/(?:€|eur)\s*(\d[\d\s.,]*)/iu',
        '/(\d[\d\s.,]*)\s*%/u',
    ];

    private const URL_PATTERN = '/https?:\/\/[^\s\)\]",]+/i';

    /**
     * @return list<string> Empty when the captions may be published.
     */
    public function validate(CaptionSet $captions, ContentItem $item): array
    {
        $violations = [];

        $fbLimit = (int) config('hub.limits.fb_caption_chars', 63206);
        $igLimit = (int) config('hub.limits.ig_caption_chars', 2200);

        if (mb_strlen($captions->facebook) > $fbLimit) {
            $violations[] = 'Facebook tekst ima '.mb_strlen($captions->facebook)." znakova, dopušteno je {$fbLimit}.";
        }

        if (mb_strlen($captions->instagram) > $igLimit) {
            $violations[] = 'Instagram tekst ima '.mb_strlen($captions->instagram)." znakova, dopušteno je {$igLimit}.";
        }

        if (mb_trim($captions->facebook) === '' || mb_trim($captions->instagram) === '') {
            $violations[] = 'Tekst objave je prazan.';
        }

        $maxTags = (int) config('hub.limits.ig_hashtags', 30);
        $tags = $captions->hashtagList();

        if (count($tags) > $maxTags) {
            $violations[] = 'Vraćeno je '.count($tags)." hashtagova, dopušteno je {$maxTags}.";
        }

        if (preg_match(self::URL_PATTERN, $captions->instagram) === 1) {
            $violations[] = 'Instagram tekst sadrži poveznicu; ondje nije klikabilna.';
        }

        $allowedUrls = $this->allowedUrls($item);

        foreach ($this->urlsIn($captions->facebook.' '.$captions->instagram) as $url) {
            if (! in_array(mb_rtrim($url, '/.'), $allowedUrls, true)) {
                $violations[] = "Poveznica {$url} ne postoji u podacima stavke.";
            }
        }

        $allowedAmounts = $this->allowedAmounts($item);

        foreach ($this->amountsIn($captions->facebook.' '.$captions->instagram.' '.$captions->alt_text) as $raw => $value) {
            if (! $this->isKnown($value, $allowedAmounts)) {
                $violations[] = "Iznos „{$raw}\" ne postoji u podacima stavke.";
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @return list<string>
     */
    private function allowedUrls(ContentItem $item): array
    {
        $urls = [$item->url, $item->cta['url'] ?? null, $item->brand?->site_url];

        foreach ($item->images ?? [] as $image) {
            $urls[] = $image['url'] ?? null;
        }

        foreach ($this->urlsIn((string) $item->body_text) as $url) {
            $urls[] = $url;
        }

        return array_values(array_unique(array_map(
            fn (string $url): string => mb_rtrim($url, '/.'),
            array_filter($urls, fn (?string $url): bool => filled($url)),
        )));
    }

    /**
     * Every amount the item itself states, in normalized numeric form.
     *
     * @return list<float>
     */
    private function allowedAmounts(ContentItem $item): array
    {
        $text = implode(' ', [
            $item->title,
            (string) $item->subtitle,
            (string) $item->body_text,
            implode(' ', array_map(fn (array $fact): string => $fact['value'], $item->facts ?? [])),
            implode(' ', $item->badges ?? []),
        ]);

        $amounts = array_values($this->numbersIn($text));

        $price = $item->price ?? [];

        foreach (['current_cents', 'old_cents'] as $key) {
            if (filled($price[$key] ?? null)) {
                $amounts[] = ((int) $price[$key]) / 100;
                $amounts[] = (float) (int) $price[$key];
            }
        }

        if (filled($price['discount_pct'] ?? null)) {
            $amounts[] = (float) (int) $price['discount_pct'];
        }

        // A "50 % off" post may legitimately also say "half price"-style round numbers that appear
        // in the item's own raw payload, so those count as stated too.
        foreach ($this->numbersIn(json_encode($item->raw ?? [], JSON_UNESCAPED_UNICODE) ?: '') as $number) {
            $amounts[] = $number;
        }

        return array_values(array_unique($amounts, SORT_REGULAR));
    }

    /**
     * Amounts and percentages found in text, keyed by how they were written.
     *
     * @return array<string, float>
     */
    private function amountsIn(string $text): array
    {
        $found = [];

        foreach (self::MONEY_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $value = $this->toFloat($match[1]);

                if ($value !== null) {
                    $found[mb_trim($match[0])] = $value;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<float>
     */
    private function numbersIn(string $text): array
    {
        preg_match_all('/\d[\d\s.,]*/u', $text, $matches);

        $numbers = [];

        foreach ($matches[0] as $raw) {
            $value = $this->toFloat($raw);

            if ($value !== null) {
                $numbers[] = $value;
            }
        }

        return $numbers;
    }

    /**
     * @param  list<float>  $allowed
     */
    private function isKnown(float $value, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            if (abs($candidate - $value) < 0.005) {
                return true;
            }
        }

        return false;
    }

    /**
     * Croatian notation: "." groups thousands, "," separates decimals. A lone "." is a decimal point
     * unless exactly three digits follow it, which is how a thousands group looks.
     */
    private function toFloat(string $raw): ?float
    {
        $value = preg_replace('/\s+/u', '', mb_trim($raw)) ?? '';
        $value = mb_rtrim($value, '.,');

        if ($value === '' || preg_match('/\d/', $value) !== 1) {
            return null;
        }

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif ($hasComma) {
            $value = str_replace(',', '.', $value);
        } elseif ($hasDot) {
            $parts = explode('.', $value);
            $last = end($parts);

            if (count($parts) > 2 || (mb_strlen($last) === 3 && $parts[0] !== '')) {
                $value = str_replace('.', '', $value);
            }
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    private function urlsIn(string $text): array
    {
        preg_match_all(self::URL_PATTERN, $text, $matches);

        return array_values(array_unique($matches[0]));
    }
}
