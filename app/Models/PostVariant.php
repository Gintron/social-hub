<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentFormat;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
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

    /**
     * A render that has not finished in this long is treated as dead (the worker was restarted).
     */
    private const RENDER_STALE_MINUTES = 15;

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

    public function metrics(): HasMany
    {
        return $this->hasMany(PostMetric::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(PostMetric::class)->latestOfMany('captured_at');
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
     * What this channel posts (`settings.format`), always one its platform supports.
     */
    public function format(): ContentFormat
    {
        $stored = $this->setting('format');
        $format = is_string($stored) ? ContentFormat::tryFrom($stored) : null;

        // Before formats were one setting, Instagram kept `format` = post|reel and Facebook `mode` = photo|link|reel.
        $format ??= match (true) {
            $stored === 'reel', $this->setting('mode') === 'reel' => ContentFormat::Video,
            $this->setting('mode') === 'link' => ContentFormat::Link,
            default => $this->legacyImageFormat(),
        };

        return $format !== null && in_array($format, $this->platform->formats(), true)
            ? $format
            : $this->platform->defaultFormat();
    }

    /**
     * Published, being published, or handed to a human with an id: nothing about it changes any more.
     */
    public function isLocked(): bool
    {
        return in_array($this->status, [VariantStatus::Published, VariantStatus::Publishing, VariantStatus::ManualDone], true)
            || filled($this->external_post_id);
    }

    public function isRendering(): bool
    {
        $render = (array) $this->setting('render', []);

        return ($render['status'] ?? null) === 'rendering'
            && CarbonImmutable::parse((string) ($render['at'] ?? 'now'))->isAfter(now()->subMinutes(self::RENDER_STALE_MINUTES));
    }

    /**
     * Why the last render for this channel did not produce media; null when it did (or none ran).
     */
    public function renderError(): ?string
    {
        $render = (array) $this->setting('render', []);

        return match ($render['status'] ?? null) {
            'failed' => (string) ($render['error'] ?? 'nepoznata greška'),
            // A worker that died mid-render never clears the mark; don't let the screen wait forever.
            'rendering' => $this->isRendering() ? null : 'render nije završio u '.self::RENDER_STALE_MINUTES.' minuta',
            default => null,
        };
    }

    public function markRendering(): void
    {
        $this->putSettings(['render' => ['status' => 'rendering', 'at' => now()->toIso8601String()]]);
    }

    public function markRendered(): void
    {
        $this->forgetSetting('render');
    }

    public function markRenderFailed(string $error): void
    {
        $this->putSettings(['render' => ['status' => 'failed', 'error' => mb_substr($error, 0, 500), 'at' => now()->toIso8601String()]]);
    }

    /**
     * Set these keys in `settings` and nothing else, in one UPDATE.
     *
     * A render job and the review screen write different keys of the same JSON at the same time.
     * Saving the whole array from a model loaded a minute earlier undid the other's change — a
     * finished video render put the format back to video after the user had switched to image.
     *
     * @param  array<string, mixed>  $values
     */
    public function putSettings(array $values): void
    {
        if ($values === []) {
            return;
        }

        $expression = 'COALESCE(settings, JSON_OBJECT())';
        $bindings = [];

        foreach ($values as $key => $value) {
            $expression = "JSON_SET({$expression}, ?, CAST(? AS JSON))";
            $bindings[] = self::settingPath((string) $key);
            $bindings[] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->writeSettings($expression, $bindings);
    }

    public function forgetSetting(string $key): void
    {
        $this->writeSettings('JSON_REMOVE(COALESCE(settings, JSON_OBJECT()), ?)', [self::settingPath($key)]);
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

    private static function settingPath(string $key): string
    {
        return '$."'.str_replace('"', '\"', $key).'"';
    }

    /**
     * Legacy variants carry no format: many images meant a carousel, one an image, none on a
     * Facebook page a link post. TikTok had only video, so it keeps its default.
     */
    private function legacyImageFormat(): ?ContentFormat
    {
        if ($this->platform->defaultFormat() === ContentFormat::Video) {
            return null;
        }

        $media = $this->relationLoaded('media') ? $this->media : $this->media()->get();

        if ($this->platform === Platform::FacebookPage && $this->setting('mode') === null && $media->isEmpty()) {
            return ContentFormat::Link;
        }

        $images = $media->reject(fn (MediaAsset $asset): bool => $asset->isVideo())->count();

        return $images > 1 ? ContentFormat::Carousel : ContentFormat::Image;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function writeSettings(string $expression, array $bindings): void
    {
        DB::update(
            "update {$this->getTable()} set settings = {$expression}, updated_at = ? where id = ?",
            [...$bindings, now(), $this->getKey()],
        );

        // Take the row's settings, not a merge into the in-memory copy: that copy may be the stale one.
        $this->settings = $this->newQuery()->whereKey($this->getKey())->first(['id', 'settings'])?->settings;
        $this->syncOriginalAttribute('settings');
    }
}
