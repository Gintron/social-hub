<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use App\Enums\VariantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The per-channel rendition of a draft: caption, media, platform settings, publish outcome.
 *
 * @property int $id
 * @property int $post_draft_id
 * @property int|null $social_account_id
 * @property Platform $platform
 * @property string $caption
 * @property string|null $link_url
 * @property array<string, mixed>|null $settings
 * @property VariantStatus $status
 * @property string $idempotency_key
 * @property string|null $external_post_id
 * @property string|null $permalink
 * @property CarbonImmutable|null $published_at
 * @property int $attempts
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $manual_posted_by
 * @property CarbonImmutable|null $manual_posted_at
 */
final class PostVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_draft_id', 'social_account_id', 'platform', 'caption', 'link_url', 'settings', 'status', 'enabled',
        'idempotency_key', 'external_post_id', 'permalink', 'published_at', 'attempts', 'error_code',
        'error_message', 'manual_posted_by', 'manual_posted_at',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(PostDraft::class, 'post_draft_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'post_variant_media')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PublishLog::class);
    }

    public function manualPoster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_posted_by');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /**
     * Atomically move queued → publishing. Returns false when another worker already took it.
     */
    public function claimForPublishing(): bool
    {
        $updated = self::query()
            ->whereKey($this->id)
            ->where('status', VariantStatus::Queued->value)
            ->update(['status' => VariantStatus::Publishing->value, 'attempts' => $this->attempts + 1, 'updated_at' => now()]);

        if ($updated === 1) {
            $this->refresh();

            return true;
        }

        return false;
    }

    protected static function booted(): void
    {
        self::creating(function (PostVariant $variant): void {
            $variant->idempotency_key ??= (string) Str::uuid();
        });
    }

    /**
     * Virtual switch used by the review screen: turning a channel off parks the variant in
     * `disabled` so publishing skips it, turning it back on returns it to the queue.
     * Anything already published is left alone.
     */
    protected function enabled(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status !== VariantStatus::Disabled,
            set: function (mixed $value): array {
                if (in_array($this->status, [VariantStatus::Published, VariantStatus::ManualDone], true)) {
                    return [];
                }

                if ((bool) $value) {
                    return $this->status === VariantStatus::Disabled ? ['status' => VariantStatus::Pending->value] : [];
                }

                return ['status' => VariantStatus::Disabled->value];
            },
        );
    }

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'settings' => 'array',
            'status' => VariantStatus::class,
            'published_at' => 'immutable_datetime',
            'manual_posted_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
