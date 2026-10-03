<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One spoken clip: some words, in one voice, as ElevenLabs returned them.
 *
 * @property int $id
 * @property int|null $brand_id
 * @property string $hash
 * @property string $voice_id
 * @property string $model
 * @property string $text
 * @property array<string, mixed>|null $settings
 * @property int $characters
 * @property int|null $cost
 * @property int $duration_ms
 * @property float|null $loudness_lufs
 * @property float|null $true_peak_db
 * @property string $disk
 * @property string $path
 * @property int|null $bytes
 * @property string|null $request_id
 */
final class Voiceover extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'hash', 'voice_id', 'model', 'text', 'settings', 'characters', 'cost', 'duration_ms',
        'loudness_lufs', 'true_peak_db', 'disk', 'path', 'bytes', 'request_id',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function fileExists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    public function durationSeconds(): float
    {
        return $this->duration_ms / 1000;
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'characters' => 'integer',
            'cost' => 'integer',
            'duration_ms' => 'integer',
            'loudness_lufs' => 'float',
            'true_peak_db' => 'float',
            'bytes' => 'integer',
        ];
    }
}
