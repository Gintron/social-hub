<?php

declare(strict_types=1);

namespace App\Drafting;

use App\Actions\PrepareVariantMedia;
use App\Enums\ActorType;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\VariantStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One post out of several items: "top 5 akcija ovog tjedna", "današnji oglasi".
 *
 * Deterministic on purpose — it picks by priority and writes the caption from the items' own
 * fields. The drafting agent later replaces the wording, not the selection.
 */
final class DigestBuilder
{
    /**
     * Instagram carousels hold ten images, and the cover takes one of them.
     */
    public const MAX_ITEMS = 9;

    public function __construct(private readonly CaptionBuilder $captions) {}

    /**
     * @param  Collection<int, SocialAccount>|list<SocialAccount>  $accounts
     */
    public function build(
        Brand $brand,
        ContentKind $kind,
        iterable $accounts,
        int $count = 5,
        ?string $headline = null,
        ?string $kicker = null,
        ActorType $actor = ActorType::Human,
        ?int $actorId = null,
        ?CarbonImmutable $scheduledAt = null,
        DraftStatus $status = DraftStatus::PendingApproval,
        bool $render = true,
    ): PostDraft {
        $accounts = collect($accounts);

        if ($accounts->isEmpty()) {
            throw new InvalidArgumentException('A digest needs at least one target account.');
        }

        $count = max(2, min($count, self::MAX_ITEMS));
        $items = $this->pick($brand, $kind, $count);

        if ($items->count() < 2) {
            throw new InvalidArgumentException("Nema dovoljno svježih stavki vrste {$kind->value} za brend {$brand->name} (pronađeno {$items->count()}, treba barem 2).");
        }

        $headline ??= $this->defaultHeadline($kind, $items->count());
        $kicker ??= $brand->name;

        $draft = DB::transaction(function () use ($brand, $items, $accounts, $headline, $actor, $actorId, $scheduledAt, $status): PostDraft {
            $draft = PostDraft::query()->create([
                'brand_id' => $brand->id,
                'kind' => PostDraft::KIND_DIGEST,
                'title' => mb_substr($headline, 0, 300),
                'status' => $scheduledAt !== null && $status === DraftStatus::Approved ? DraftStatus::Scheduled : $status,
                'scheduled_at' => $scheduledAt,
                'created_by_type' => $actor,
                'created_by_id' => $actorId,
            ]);

            foreach ($items->values() as $position => $item) {
                $draft->contentItems()->attach($item->id, ['position' => $position, 'checksum' => $item->checksum]);
            }

            foreach ($accounts as $account) {
                PostVariant::query()->create([
                    'post_draft_id' => $draft->id,
                    'social_account_id' => $account->id,
                    'platform' => $account->platform,
                    'caption' => $this->captions->digest($account->platform, $items, $brand, $headline),
                    'link_url' => $brand->site_url,
                    // A digest is a carousel on every channel, TikTok included (a photo post).
                    'settings' => ['format' => ContentFormat::Carousel->value],
                    'status' => VariantStatus::Pending,
                ]);
            }

            return $draft;
        });

        if ($render) {
            // Grouped by orientation: one square set for Facebook and Instagram, one vertical for TikTok.
            app(PrepareVariantMedia::class)->execute($draft->variants()->get(), kicker: $kicker);
        }

        return $draft->load(['variants.account', 'contentItems']);
    }

    /**
     * Freshest, highest-priority items that no draft has used yet.
     *
     * @return Collection<int, ContentItem>
     */
    public function pick(Brand $brand, ContentKind $kind, int $count): Collection
    {
        return ContentItem::query()
            ->where('brand_id', $brand->id)
            ->where('kind', $kind->value)
            ->live()
            ->notDrafted()
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->limit($count)
            ->get();
    }

    private function defaultHeadline(ContentKind $kind, int $count): string
    {
        return match ($kind) {
            ContentKind::Deal => "Top {$count} akcija ovog tjedna",
            ContentKind::Job => "Top {$count} oglasa ovog tjedna",
            ContentKind::Event => "{$count} događaja koje ne propuštaš",
            default => "Izdvojeno: {$count} novosti",
        };
    }
}
