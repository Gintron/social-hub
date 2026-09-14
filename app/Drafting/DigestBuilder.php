<?php

declare(strict_types=1);

namespace App\Drafting;

use App\Actions\PrepareVariantMedia;
use App\Actions\UpdateVariant;
use App\Enums\ActorType;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Enums\VariantStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
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

    /**
     * An item may appear in the next digest only once this many days have passed since the last.
     */
    public const REPEAT_AFTER_DAYS = 7;

    public function __construct(private readonly CaptionBuilder $captions) {}

    /**
     * @param  Collection<int, SocialAccount>|list<SocialAccount>  $accounts
     * @param  string|null  $headline  `{count}` becomes the number of items picked.
     * @param  bool  $includePosted  Also take items that already had a post of their own (a recurring
     *                               digest is a roundup of the week, not of what nobody posted).
     * @param  array<string, array<string, mixed>>  $channelSettings  platform value => variant settings
     * @param  string|null  $tag  Only items carrying this feed tag (a chain, a city).
     * @param  array<string, ContentFormat>  $formats  platform value => carousel or video; carousel by default.
     * @param  string|null  $series  The digest series building this draft (DigestSeries::$key).
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
        bool $includePosted = false,
        array $channelSettings = [],
        ?string $tag = null,
        array $formats = [],
        ?string $series = null,
    ): PostDraft {
        $accounts = collect($accounts);

        if ($accounts->isEmpty()) {
            throw new InvalidArgumentException('A digest needs at least one target account.');
        }

        $count = max(2, min($count, self::MAX_ITEMS));
        $items = $this->pick($brand, $kind, $count, $includePosted, $tag);

        if ($items->count() < 2) {
            $scope = $tag !== null ? " s oznakom {$tag}" : '';

            throw new InvalidArgumentException("Nema dovoljno svježih stavki vrste {$kind->value}{$scope} za brend {$brand->name} (pronađeno {$items->count()}, treba barem 2).");
        }

        $headline = str_replace('{count}', (string) $items->count(), $headline ?? $this->defaultHeadline($kind, $items->count()));
        $kicker ??= $brand->name;

        $draft = DB::transaction(function () use ($brand, $items, $accounts, $headline, $actor, $actorId, $scheduledAt, $status, $channelSettings, $formats, $series): PostDraft {
            $draft = PostDraft::query()->create([
                'brand_id' => $brand->id,
                'kind' => PostDraft::KIND_DIGEST,
                'digest_series' => $series,
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
                    // The same slides either way: a carousel (a photo post on TikTok), or a Reel of them.
                    'settings' => [
                        ...Arr::only($channelSettings[$account->platform->value] ?? [], UpdateVariant::EDITABLE_SETTINGS),
                        'format' => self::formatFor($account->platform, $formats)->value,
                    ],
                    'status' => VariantStatus::Pending,
                ]);
            }

            return $draft;
        });

        if ($render) {
            // Grouped by format and orientation: one 4:5 set for Facebook and Instagram, one vertical
            // for TikTok, one video for every channel that posts it as a Reel.
            app(PrepareVariantMedia::class)->execute($draft->variants()->get(), kicker: $kicker);
        }

        return $draft->load(['variants.account', 'contentItems']);
    }

    /**
     * Freshest, highest-priority items that no draft has used yet — or, with $includePosted, that
     * no digest has used in the last REPEAT_AFTER_DAYS days.
     *
     * @return Collection<int, ContentItem>
     */
    public function pick(Brand $brand, ContentKind $kind, int $count, bool $includePosted = false, ?string $tag = null): Collection
    {
        $since = CarbonImmutable::now()->subDays(self::REPEAT_AFTER_DAYS);

        return ContentItem::query()
            ->where('brand_id', $brand->id)
            ->where('kind', $kind->value)
            ->live()
            ->when($tag !== null, fn ($query) => $query->whereJsonContains('tags', $tag))
            ->when(! $includePosted, fn ($query) => $query->notDrafted())
            ->when($includePosted, fn ($query) => $query->whereDoesntHave('postDrafts', fn ($drafts) => $drafts
                ->where('kind', PostDraft::KIND_DIGEST)
                ->where('post_drafts.created_at', '>=', $since)))
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->limit($count)
            ->get();
    }

    /**
     * A digest is always slides: a carousel, or a video made of them. Anything the channel cannot
     * take — or a single image, which would drop every item but the cover — is a carousel.
     *
     * @param  array<string, ContentFormat>  $formats
     */
    private static function formatFor(Platform $platform, array $formats): ContentFormat
    {
        $format = $formats[$platform->value] ?? ContentFormat::Carousel;

        return $format === ContentFormat::Video && in_array($format, $platform->formats(), true)
            ? ContentFormat::Video
            : ContentFormat::Carousel;
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
