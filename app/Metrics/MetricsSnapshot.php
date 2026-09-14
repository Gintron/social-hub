<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * What a platform said about one post, mapped onto the hub's columns. Null = not reported.
 */
final readonly class MetricsSnapshot
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?int $views = null,
        public ?int $reach = null,
        public ?int $likes = null,
        public ?int $comments = null,
        public ?int $shares = null,
        public ?int $saves = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, int|null>  $values  Column => value; unknown columns are ignored.
     * @param  array<string, mixed>  $raw
     */
    public static function fromColumns(array $values, array $raw = []): self
    {
        return new self(
            views: $values['views'] ?? null,
            reach: $values['reach'] ?? null,
            likes: $values['likes'] ?? null,
            comments: $values['comments'] ?? null,
            shares: $values['shares'] ?? null,
            saves: $values['saves'] ?? null,
            raw: $raw,
        );
    }

    /**
     * Not a single number came back — nothing worth a row.
     */
    public function isEmpty(): bool
    {
        return array_filter($this->columns(), fn (?int $value): bool => $value !== null) === [];
    }

    /**
     * @return array<string, int|null>
     */
    public function columns(): array
    {
        return [
            'views' => $this->views,
            'reach' => $this->reach,
            'likes' => $this->likes,
            'comments' => $this->comments,
            'shares' => $this->shares,
            'saves' => $this->saves,
        ];
    }
}
