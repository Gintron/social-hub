<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A site/brand the hub publishes for (studentski-poslovi, radim.hr, uselisto…).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $site_url
 * @property string|null $logo_path
 * @property array<string, string>|null $colors
 * @property array<string, mixed>|null $voice
 * @property array<int, array{day?: string, from: string, to: string}>|null $posting_windows
 * @property string $timezone
 */
final class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'site_url', 'logo_path', 'colors', 'voice', 'posting_windows', 'timezone',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function postDrafts(): HasMany
    {
        return $this->hasMany(PostDraft::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'voice' => 'array',
            'posting_windows' => 'array',
        ];
    }
}
