<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per source × platform switch: when enabled, new items are drafted and scheduled without human approval.
 *
 * @property int $id
 * @property int $source_id
 * @property Platform $platform
 * @property bool $enabled
 * @property int $delay_minutes
 * @property int|null $daily_cap
 */
final class AutoPublishRule extends Model
{
    protected $fillable = ['source_id', 'platform', 'enabled', 'delay_minutes', 'daily_cap'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'enabled' => 'boolean',
            'delay_minutes' => 'integer',
            'daily_cap' => 'integer',
        ];
    }
}
