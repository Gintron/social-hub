<?php

declare(strict_types=1);

namespace App\Sources;

use App\Enums\ContentKind;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One Social Feed v1 item, decoded and normalized. Adapter-agnostic: every source produces these.
 */
final readonly class ContentItemData
{
    /**
     * @param  list<array{label: string, value: string}>  $facts
     * @param  list<string>  $badges
     * @param  array<string, mixed>|null  $price
     * @param  array{label: string, url: string}|null  $cta
     * @param  list<array{url: string, role: string, alt: string|null}>  $images
     * @param  list<string>  $tags
     * @param  array<string, mixed>|null  $raw
     */
    public function __construct(
        public string $externalId,
        public ContentKind $kind,
        public string $title,
        public ?string $subtitle,
        public ?string $bodyText,
        public array $facts,
        public array $badges,
        public ?array $price,
        public ?array $cta,
        public string $url,
        public array $images,
        public ?int $priority,
        public array $tags,
        public ?CarbonImmutable $publishedAt,
        public ?CarbonImmutable $expiresAt,
        public CarbonImmutable $updatedAt,
        public ?array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $item  One element of a validated Social Feed v1 `items` array.
     */
    public static function fromFeedItem(array $item): self
    {
        $kind = ContentKind::tryFrom((string) ($item['kind'] ?? ''))
            ?? throw new InvalidArgumentException('Unknown item kind: '.json_encode($item['kind'] ?? null));

        $images = [];
        foreach ($item['images'] ?? [] as $image) {
            $images[] = [
                'url' => (string) $image['url'],
                'role' => (string) ($image['role'] ?? 'primary'),
                'alt' => isset($image['alt']) ? (string) $image['alt'] : null,
            ];
        }

        $facts = [];
        foreach ($item['facts'] ?? [] as $fact) {
            $facts[] = ['label' => (string) $fact['label'], 'value' => (string) $fact['value']];
        }

        return new self(
            externalId: (string) $item['id'],
            kind: $kind,
            title: mb_trim((string) $item['title']),
            subtitle: self::nullableString($item['subtitle'] ?? null),
            bodyText: self::nullableString($item['body_text'] ?? null),
            facts: $facts,
            badges: array_values(array_map('strval', $item['badges'] ?? [])),
            price: isset($item['price']) && is_array($item['price']) ? $item['price'] : null,
            cta: isset($item['cta']) && is_array($item['cta']) ? ['label' => (string) $item['cta']['label'], 'url' => (string) $item['cta']['url']] : null,
            url: (string) $item['url'],
            images: $images,
            priority: isset($item['priority']) ? (int) $item['priority'] : null,
            tags: array_values(array_map('strval', $item['tags'] ?? [])),
            publishedAt: self::nullableDate($item['published_at'] ?? null),
            expiresAt: self::nullableDate($item['expires_at'] ?? null),
            updatedAt: CarbonImmutable::parse((string) $item['updated_at'])->utc(),
            raw: isset($item['raw']) && is_array($item['raw']) ? $item['raw'] : null,
        );
    }

    /**
     * Content fingerprint: changes when anything a post could show changes. `updated_at` is
     * deliberately excluded so a touch without content change does not flag drafts as stale.
     */
    public function checksum(): string
    {
        return hash('sha256', json_encode([
            $this->kind->value, $this->title, $this->subtitle, $this->bodyText, $this->facts, $this->badges,
            $this->price, $this->cta, $this->url, $this->images, $this->priority, $this->tags,
            $this->publishedAt?->toIso8601ZuluString(), $this->expiresAt?->toIso8601ZuluString(), $this->raw,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Attributes for ContentItem::fill() (everything except source/brand/seen timestamps).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'external_id' => $this->externalId,
            'kind' => $this->kind,
            'title' => mb_substr($this->title, 0, 300),
            'subtitle' => $this->subtitle !== null ? mb_substr($this->subtitle, 0, 300) : null,
            'body_text' => $this->bodyText,
            'facts' => $this->facts,
            'badges' => $this->badges,
            'price' => $this->price,
            'cta' => $this->cta,
            'url' => $this->url,
            'images' => $this->images,
            'priority' => $this->priority,
            'tags' => $this->tags,
            'raw' => $this->raw,
            'published_at' => $this->publishedAt,
            'expires_at' => $this->expiresAt,
            'source_updated_at' => $this->updatedAt,
            'checksum' => $this->checksum(),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function nullableDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->utc();
    }
}
