<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\Platform;
use App\Models\ContentItem;
use App\Models\PostVariant;
use InvalidArgumentException;

/**
 * Does a post show or name alcohol on a channel that does not take it?
 *
 * A retailer's catalogue is pictures of its leaflet, so the hub cannot see a wine on page 14. The source
 * keeps drinks out of the pages it shows (docs/catalog-video.md, "Alkohol na stranicama"); `restricted` marks
 * an item that shows or sells alcohol by itself, such as a deal (docs/social-feed-v1.md). The words are the
 * second line: an item the source did not mark, or a caption a model wrote with "uz vino" in it.
 *
 * Deterministic on purpose: the words live in config/restricted_goods.php and no model decides. Used
 * at selection (auto-publish, digests) and again right before a publisher runs (PublishVariantJob).
 */
final class RestrictedGoodsCheck
{
    public const ALCOHOL = 'alcohol';

    /** @var array{terms: string, exceptions: string, volume: string}|null */
    private ?array $patterns = null;

    /**
     * Why this item may not go out on this channel, or null when it may.
     */
    public function itemProblem(ContentItem $item, string $platform): ?string
    {
        if (! in_array($platform, $this->blockedOn($item), true)) {
            return null;
        }

        return "Stavka '{$item->title}' sadrži alkohol; {$this->channel($platform)} ga ne objavljuje.";
    }

    /**
     * The channels this item must stay off: every configured channel when it names alcohol, none when it does not.
     * One text scan however many channels ask, so selection can call this once per item.
     *
     * @return list<string>
     */
    public function blockedOn(ContentItem $item): array
    {
        return $this->namesAlcohol($item) ? $this->configuredPlatforms() : [];
    }

    /**
     * Why this variant may not go out, or null. Checks every item of its draft (a digest holds several)
     * and the caption, which a model may have written from words that no item held.
     */
    public function variantProblem(PostVariant $variant): ?string
    {
        $platform = $variant->platform->value;

        if (! in_array($platform, $this->configuredPlatforms(), true)) {
            return null;
        }

        foreach ($variant->draft?->contentItems ?? [] as $item) {
            if (($problem = $this->itemProblem($item, $platform)) !== null) {
                return $problem;
            }
        }

        if ($this->textNamesAlcohol((string) $variant->caption)) {
            return "Tekst objave spominje alkohol; {$this->channel($platform)} ga ne objavljuje.";
        }

        return null;
    }

    /**
     * The channels the words block, each one checked against Platform. A name that is no Platform would switch its
     * block off without a sound, so it is an error instead.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function configuredPlatforms(): array
    {
        $platforms = [];

        foreach ((array) config('restricted_goods.platforms', []) as $value) {
            $platform = (string) $value;

            if (Platform::tryFrom($platform) === null) {
                throw new InvalidArgumentException("config/restricted_goods.php: '{$platform}' nije kanal (Platform).");
            }

            $platforms[] = $platform;
        }

        return $platforms;
    }

    private function namesAlcohol(ContentItem $item): bool
    {
        if (in_array(self::ALCOHOL, $item->restricted ?? [], true)) {
            return true;
        }

        return $this->textNamesAlcohol(implode("\n", $this->wordsOf($item)));
    }

    /**
     * Every text the item shows or sells with. URLs are skipped: a slug in a link is not a label.
     *
     * @return list<string>
     */
    private function wordsOf(ContentItem $item): array
    {
        $words = [$item->title, $item->subtitle, $item->body_text, ...($item->badges ?? []), ...($item->tags ?? [])];

        foreach ($item->facts ?? [] as $fact) {
            $words[] = $fact['label'] ?? null;
            $words[] = $fact['value'] ?? null;
        }

        $raw = $item->raw ?? [];

        array_walk_recursive($raw, function (mixed $value) use (&$words): void {
            $words[] = $value;
        });

        return array_values(array_filter(
            $words,
            fn (mixed $word): bool => is_string($word) && $word !== '' && ! str_starts_with($word, 'http'),
        ));
    }

    private function textNamesAlcohol(string $text): bool
    {
        $patterns = $this->patterns();
        $text = (string) preg_replace($patterns['exceptions'], ' ', mb_strtolower($text));

        return preg_match($patterns['terms'], $text) === 1 || preg_match($patterns['volume'], $text) === 1;
    }

    /**
     * Compiled once per instance. An empty list compiles to a pattern that never matches: an empty
     * alternation would match everywhere (and an empty exception would split every word). A trailing `*`
     * in a term takes any ending, so "rakij*" matches rakija, rakije and rakijom.
     *
     * @return array{terms: string, exceptions: string, volume: string}
     */
    private function patterns(): array
    {
        return $this->patterns ??= [
            'terms' => $this->alternation(
                array_map(
                    fn (string $term): string => str_replace('\*', '\p{L}*', preg_quote($term, '/')),
                    $this->list('alcohol.terms'),
                ),
                '/(?<![\p{L}\p{N}])(?:%s)(?![\p{L}\p{N}])/iu',
            ),
            'exceptions' => $this->alternation($this->list('alcohol.exceptions'), '/(?:%s)/iu'),
            'volume' => '/(?<![\p{L}\p{N}])'.(((string) config('restricted_goods.alcohol.volume', '')) !== '' ? (string) config('restricted_goods.alcohol.volume') : '(?!)').'/iu',
        ];
    }

    /**
     * @param  list<string>  $parts  Already escaped alternatives.
     */
    private function alternation(array $parts, string $pattern): string
    {
        return sprintf($pattern, $parts === [] ? '(?!)' : implode('|', $parts));
    }

    /**
     * @return list<string>
     */
    private function list(string $key): array
    {
        return array_values(array_map('strval', (array) config("restricted_goods.{$key}", [])));
    }

    private function channel(string $platform): string
    {
        return Platform::tryFrom($platform)?->label() ?? $platform;
    }
}
