<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One normalized Social Feed v1 item pulled from a source (a job, a deal, an article…).
 *
 * @property int $id
 * @property int $source_id
 * @property int $brand_id
 * @property string $external_id
 * @property ContentKind $kind
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $body_text
 * @property list<array{label: string, value: string}> $facts
 * @property list<string> $badges
 * @property array<string, mixed>|null $price
 * @property array{label: string, url: string}|null $cta
 * @property string $url
 * @property list<array{url: string, role?: string, alt?: string|null}> $images
 * @property int|null $priority
 * @property list<string> $tags
 * @property array<string, mixed>|null $raw
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $link_dead_at
 * @property CarbonImmutable $source_updated_at
 * @property string $checksum
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 */
final class ContentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id', 'brand_id', 'external_id', 'kind', 'title', 'subtitle', 'body_text', 'facts', 'badges',
        'price', 'cta', 'url', 'images', 'priority', 'tags', 'raw', 'published_at', 'expires_at',
        'source_updated_at', 'checksum', 'first_seen_at', 'last_seen_at',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function postDrafts(): BelongsToMany
    {
        return $this->belongsToMany(PostDraft::class, 'post_draft_content_items')->withPivot('position');
    }

    /**
     * Not expired, and its page was not found gone the last time a pick looked (LinkPreflight::isAlive()).
     *
     * @param  Builder<ContentItem>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('link_dead_at')->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * @param  Builder<ContentItem>  $query
     */
    public function scopeNotDrafted(Builder $query): void
    {
        $query->whereDoesntHave('postDrafts');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * First image in the given role.
     *
     * Only the main picture falls back to "whatever image there is" — asking for a logo and being
     * handed the product photo puts the product in the little logo badge of every template.
     */
    public function imageUrl(string $role = 'primary'): ?string
    {
        $images = $this->images ?? [];

        foreach ($images as $image) {
            if (($image['role'] ?? 'primary') === $role && filled($image['url'] ?? null)) {
                return (string) $image['url'];
            }
        }

        if ($role !== 'primary') {
            return null;
        }

        return filled($images[0]['url'] ?? null) ? (string) $images[0]['url'] : null;
    }

    public function fact(string $label): ?string
    {
        foreach ($this->facts ?? [] as $fact) {
            if (mb_strtoupper((string) ($fact['label'] ?? '')) === mb_strtoupper($label)) {
                return (string) $fact['value'];
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'kind' => ContentKind::class,
            'facts' => 'array',
            'badges' => 'array',
            'price' => 'array',
            'cta' => 'array',
            'images' => 'array',
            'tags' => 'array',
            'raw' => 'array',
            'priority' => 'integer',
            'published_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'link_dead_at' => 'immutable_datetime',
            'source_updated_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
