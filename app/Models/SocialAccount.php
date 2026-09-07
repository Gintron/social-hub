<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A connected channel: a Facebook Page, an Instagram Business account, a (manual) Facebook group, a TikTok account.
 *
 * @property int $id
 * @property int $brand_id
 * @property Platform $platform
 * @property string $name
 * @property string $external_id
 * @property string|null $access_token
 * @property CarbonImmutable|null $token_expires_at
 * @property array<string, mixed>|null $meta
 * @property AccountStatus $status
 * @property CarbonImmutable|null $last_verified_at
 */
final class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'platform', 'name', 'external_id', 'access_token', 'refresh_token', 'token_expires_at',
        'refresh_token_expires_at', 'meta', 'status', 'last_verified_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function postVariants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }

    /**
     * @param  Builder<SocialAccount>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', AccountStatus::Active->value);
    }

    public function isUsable(): bool
    {
        if ($this->status !== AccountStatus::Active) {
            return false;
        }

        if ($this->platform->isManual()) {
            return true;
        }

        return filled($this->access_token)
            && ($this->token_expires_at === null || $this->token_expires_at->isFuture());
    }

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'refresh_token_expires_at' => 'immutable_datetime',
            'meta' => 'array',
            'status' => AccountStatus::class,
            'last_verified_at' => 'immutable_datetime',
        ];
    }
}
