<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request/response pair (token redacted) exchanged with a platform while publishing a variant.
 *
 * @property int $id
 * @property int $post_variant_id
 * @property string $event
 * @property int|null $http_status
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 */
final class PublishLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['post_variant_id', 'event', 'http_status', 'request', 'response'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class, 'post_variant_id');
    }

    protected function casts(): array
    {
        return [
            'request' => 'array',
            'response' => 'array',
            'http_status' => 'integer',
        ];
    }
}
