<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * A rendered image (or later: video) stored on a public disk so Meta can fetch it.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $post_draft_id
 * @property string $template_key
 * @property array<string, mixed>|null $params
 * @property int $width
 * @property int $height
 * @property string $format
 * @property string $disk
 * @property string $path
 * @property int|null $bytes
 * @property string|null $checksum
 */
final class MediaAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'post_draft_id', 'template_key', 'params', 'width', 'height', 'duration_ms',
        'poster_media_asset_id', 'format', 'disk', 'path', 'bytes', 'checksum',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(PostDraft::class, 'post_draft_id');
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(PostVariant::class, 'post_variant_media')->withPivot('position');
    }

    /**
     * Absolute, publicly fetchable URL (Meta cURLs media at publish time).
     */
    public function publicUrl(): string
    {
        $url = Storage::disk($this->disk)->url($this->path);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return mb_rtrim((string) config('app.url'), '/').'/'.mb_ltrim($url, '/');
    }

    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(self::class, 'poster_media_asset_id');
    }

    public function isVideo(): bool
    {
        return $this->format === 'mp4';
    }

    public function durationSeconds(): ?float
    {
        return $this->duration_ms === null ? null : $this->duration_ms / 1000;
    }

    public function ratio(): float
    {
        return $this->height === 0 ? 0.0 : $this->width / $this->height;
    }

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'bytes' => 'integer',
        ];
    }
}
