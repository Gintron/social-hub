<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthType;
use App\Enums\SourceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a brand's content comes from: a Social Feed v1 endpoint, an RSS feed, or webhook pushes.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property SourceType $type
 * @property string|null $base_url
 * @property AuthType $auth_type
 * @property string|null $secret
 * @property array<string, mixed>|null $config
 * @property CarbonImmutable|null $sync_since
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_error
 * @property bool $enabled
 */
final class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'name', 'type', 'base_url', 'auth_type', 'secret', 'config',
        'sync_since', 'last_synced_at', 'last_error', 'enabled',
    ];

    protected $hidden = ['secret'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function autoPublishRules(): HasMany
    {
        return $this->hasMany(AutoPublishRule::class);
    }

    /**
     * @param  Builder<Source>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * @param  Builder<Source>  $query
     */
    public function scopePull(Builder $query): void
    {
        $query->whereIn('type', array_map(
            fn (SourceType $type): string => $type->value,
            array_filter(SourceType::cases(), fn (SourceType $type): bool => $type->isPull()),
        ));
    }

    /**
     * Read a per-source setting with a default.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'auth_type' => AuthType::class,
            'secret' => 'encrypted',
            'config' => 'array',
            'sync_since' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'enabled' => 'boolean',
        ];
    }
}
