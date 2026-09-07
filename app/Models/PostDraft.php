<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActorType;
use App\Enums\DraftStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * One post idea: the content item(s) it is about plus one variant per target channel.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $kind
 * @property string|null $title
 * @property DraftStatus $status
 * @property CarbonImmutable|null $scheduled_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property ActorType $created_by_type
 * @property int|null $created_by_id
 * @property int|null $agent_run_id
 * @property string|null $notes
 */
final class PostDraft extends Model
{
    use HasFactory;

    public const KIND_SINGLE = 'single';

    public const KIND_DIGEST = 'digest';

    protected $fillable = [
        'brand_id', 'kind', 'title', 'status', 'scheduled_at', 'approved_by', 'approved_at',
        'created_by_type', 'created_by_id', 'agent_run_id', 'notes',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function contentItems(): BelongsToMany
    {
        return $this->belongsToMany(ContentItem::class, 'post_draft_content_items')
            ->withPivot(['position', 'checksum'])
            ->orderByPivot('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /**
     * Items whose content changed on the source site after this draft was written.
     *
     * @return \Illuminate\Support\Collection<int, ContentItem>
     */
    public function staleItems(): \Illuminate\Support\Collection
    {
        return $this->contentItems
            ->filter(fn (ContentItem $item): bool => filled($item->pivot->checksum) && $item->pivot->checksum !== $item->checksum)
            ->values();
    }

    public function publishLogs(): HasManyThrough
    {
        return $this->hasManyThrough(PublishLog::class, PostVariant::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * @param  Builder<PostDraft>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', DraftStatus::Scheduled->value)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }

    /**
     * Recompute the draft status from its variants after a publish attempt.
     */
    public function refreshStatusFromVariants(): void
    {
        $variants = $this->variants()->get();

        if ($variants->isEmpty()) {
            return;
        }

        $relevant = $variants->reject(fn (PostVariant $variant): bool => in_array($variant->status->value, ['disabled', 'skipped'], true));

        if ($relevant->isEmpty()) {
            return;
        }

        $done = $relevant->filter(fn (PostVariant $variant): bool => in_array($variant->status->value, ['published', 'manual_done'], true));
        $failed = $relevant->filter(fn (PostVariant $variant): bool => $variant->status->value === 'failed');
        $inFlight = $relevant->filter(fn (PostVariant $variant): bool => in_array($variant->status->value, ['queued', 'publishing'], true));

        $status = match (true) {
            $done->count() === $relevant->count() => DraftStatus::Published,
            $inFlight->isNotEmpty() => DraftStatus::Publishing,
            $done->isNotEmpty() && $failed->isNotEmpty() => DraftStatus::PartiallyPublished,
            $done->isNotEmpty() => DraftStatus::PartiallyPublished,
            $failed->isNotEmpty() => DraftStatus::Failed,
            default => $this->status,
        };

        if ($status !== $this->status) {
            $this->forceFill(['status' => $status])->save();
        }
    }

    protected function casts(): array
    {
        return [
            'status' => DraftStatus::class,
            'created_by_type' => ActorType::class,
            'scheduled_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
