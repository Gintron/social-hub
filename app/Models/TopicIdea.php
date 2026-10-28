<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TopicStatus;
use App\Persona\ShortScript;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One thing an AI character could talk about on a day, and the script of the short once a person picks it.
 * Changed only through App\Actions (SuggestTopics, ChooseTopic, RejectTopic, UpdateTopicScript, ApproveTopicScript,
 * ProduceShort) and the jobs they start.
 *
 * @property int $id
 * @property int $brand_id
 * @property CarbonImmutable $for_date
 * @property string $title
 * @property string $hook
 * @property string $angle
 * @property string|null $reason
 * @property TopicStatus $status
 * @property array<string, mixed>|null $script
 * @property string|null $script_error
 * @property array<string, mixed>|null $production
 * @property string|null $production_error
 * @property int|null $post_draft_id
 * @property string|null $model
 * @property CarbonImmutable|null $chosen_at
 * @property CarbonImmutable|null $scripted_at
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by
 * @property-read Brand $brand
 * @property-read PostDraft|null $postDraft
 */
final class TopicIdea extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'for_date', 'title', 'hook', 'angle', 'reason', 'status', 'script', 'script_error', 'production', 'production_error', 'post_draft_id', 'model',
        'chosen_at', 'scripted_at', 'approved_at', 'approved_by',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * The post the short became (ProduceShort).
     */
    public function postDraft(): BelongsTo
    {
        return $this->belongsTo(PostDraft::class);
    }

    /**
     * The script, or null when there is none (yet) or what is stored is not one.
     */
    public function shortScript(): ?ShortScript
    {
        if (! is_array($this->script)) {
            return null;
        }

        try {
            return ShortScript::fromArray($this->script);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    protected function casts(): array
    {
        return [
            'for_date' => 'immutable_date',
            'status' => TopicStatus::class,
            'script' => 'array',
            'production' => 'array',
            'chosen_at' => 'immutable_datetime',
            'scripted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
