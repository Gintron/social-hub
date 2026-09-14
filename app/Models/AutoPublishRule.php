<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentFormat;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per source × platform switch: when enabled, new items are drafted and scheduled without human approval.
 *
 * The rule also says how the channel posts — a Reel on Instagram, a photo post delivered to the
 * TikTok inbox — so a campaign is set up in the panel, not in code.
 *
 * @property int $id
 * @property int $source_id
 * @property Platform $platform
 * @property bool $enabled
 * @property int $delay_minutes
 * @property int|null $daily_cap
 * @property ContentFormat|null $format
 * @property array<string, mixed>|null $settings
 */
final class AutoPublishRule extends Model
{
    protected $fillable = ['source_id', 'platform', 'enabled', 'delay_minutes', 'daily_cap', 'format', 'settings'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * What this rule asks of every variant it creates, in the shape CreateDraft takes.
     *
     * @return array{format?: ContentFormat, settings?: array<string, mixed>}
     */
    public function channelOptions(): array
    {
        return array_filter([
            'format' => $this->format,
            'settings' => $this->settings ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'enabled' => 'boolean',
            'delay_minutes' => 'integer',
            'daily_cap' => 'integer',
            'format' => ContentFormat::class,
            'settings' => 'array',
        ];
    }
}
