<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A post's numbers as the platform reported them at one moment. Null means the platform did not
 * report that number for this kind of post, not zero.
 *
 * @property int $id
 * @property int $post_variant_id
 * @property CarbonImmutable $captured_at
 * @property int|null $views
 * @property int|null $reach
 * @property int|null $likes
 * @property int|null $comments
 * @property int|null $shares
 * @property int|null $saves
 * @property array<string, mixed>|null $raw
 */
final class PostMetric extends Model
{
    protected $fillable = ['post_variant_id', 'captured_at', 'views', 'reach', 'likes', 'comments', 'shares', 'saves', 'raw'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class, 'post_variant_id');
    }

    /**
     * Likes, comments, shares and saves together — what the platforms themselves call engagement.
     */
    public function interactions(): int
    {
        return (int) $this->likes + (int) $this->comments + (int) $this->shares + (int) $this->saves;
    }

    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'views' => 'integer',
            'reach' => 'integer',
            'likes' => 'integer',
            'comments' => 'integer',
            'shares' => 'integer',
            'saves' => 'integer',
            'raw' => 'array',
        ];
    }
}
