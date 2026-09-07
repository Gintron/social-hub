<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One invocation of the drafting agent (or an MCP session summary): model, tokens, outcome.
 *
 * @property int $id
 * @property int|null $brand_id
 * @property string $type
 * @property string|null $model
 * @property string $status
 * @property string|null $input_summary
 * @property array<string, mixed>|null $output
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property string|null $error
 */
final class AgentRun extends Model
{
    protected $fillable = [
        'brand_id', 'type', 'model', 'status', 'input_summary', 'output', 'input_tokens', 'output_tokens', 'error',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(PostDraft::class);
    }

    protected function casts(): array
    {
        return [
            'output' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }
}
