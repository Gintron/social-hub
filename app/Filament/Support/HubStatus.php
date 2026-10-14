<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\AccountStatus;
use App\Enums\DraftStatus;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\ContentItems\ContentItemResource;
use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Filament\Resources\Sources\SourceResource;
use App\Models\Brand;
use App\Models\PostDraft;
use App\Models\SocialAccount;
use App\Models\Source;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the dashboard asks of a person: the work waiting for them and the parts of the hub that
 * have stopped doing theirs. One place, so the numbers on the dashboard, in the navigation badge
 * and in the tests are the same numbers.
 */
final class HubStatus
{
    /**
     * A source that has not been read for this long is as good as broken, even without an error.
     */
    public const SOURCE_STALE_AFTER_HOURS = 36;

    /**
     * A Page token this close to its end is worth a reconnect before it fails a post.
     */
    public const TOKEN_WARNING_DAYS = 7;

    /**
     * The one definition of "this account needs a person", for the count and for the per-brand table.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function accountsNeedingAttention(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', AccountStatus::Disabled->value)
            ->where(fn ($query) => $query
                ->where('status', AccountStatus::NeedsReconnect->value)
                ->orWhere('token_expires_at', '<=', now()->addDays(self::TOKEN_WARNING_DAYS)));
    }

    /**
     * The one definition of "this source is not delivering", for the count and for the per-brand table.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function sourcesNeedingAttention(Builder $query): Builder
    {
        // A webhook source is pushed to, not read: its silence is not a failure.
        return $query
            ->enabled()
            ->pull()
            ->where(fn ($query) => $query
                ->whereNotNull('last_error')
                ->orWhere('last_synced_at', '<', now()->subHours(self::SOURCE_STALE_AFTER_HOURS)));
    }

    public function pendingApproval(): int
    {
        return PostDraft::query()->where('status', DraftStatus::PendingApproval->value)->count();
    }

    /**
     * Posts that are approved and waiting for their time, in the coming week.
     */
    public function scheduledThisWeek(): int
    {
        return PostDraft::query()
            ->whereIn('status', [DraftStatus::Approved->value, DraftStatus::Scheduled->value])
            ->whereBetween('scheduled_at', [now(), now()->addWeek()])
            ->count();
    }

    /**
     * Posts that did not go out, or only partly. A person has to decide what happens to them.
     */
    public function needFixing(): int
    {
        return PostDraft::query()
            ->whereIn('status', [DraftStatus::Failed->value, DraftStatus::PartiallyPublished->value])
            ->count();
    }

    /**
     * Accounts the hub can no longer post to, or soon will not be able to.
     */
    public function accountsToReconnect(): int
    {
        return self::accountsNeedingAttention(SocialAccount::query())->count();
    }

    /**
     * Sources that are switched on but failed their last read, or have not been read in a long time.
     */
    public function sourcesWithProblems(): int
    {
        return self::sourcesNeedingAttention(Source::query())->count();
    }

    /**
     * The steps from an empty hub to a first post, in the order they have to be done. Each knows
     * where in the panel it is done.
     *
     * @return list<array{label: string, hint: string, done: bool, url: string}>
     */
    public function setupSteps(): array
    {
        return [
            [
                'label' => 'Dodaj brend',
                'hint' => 'Brend je jedna stranica ili marka: ima svoje boje, tekstove, račune i termine objave.',
                'done' => Brand::query()->exists(),
                'url' => BrandResource::getUrl('create'),
            ],
            [
                'label' => 'Poveži izvor sadržaja',
                'hint' => 'Stranica iz koje hub čita ponude, oglase ili članke (Social Feed v1). Bez izvora nema što objaviti.',
                'done' => Source::query()->exists(),
                'url' => SourceResource::getUrl('create'),
            ],
            [
                'label' => 'Poveži račune društvenih mreža',
                'hint' => 'Facebook stranica, Instagram, TikTok ili YouTube na koje hub objavljuje.',
                'done' => SocialAccount::query()->where('status', AccountStatus::Active->value)->exists(),
                'url' => SocialAccountResource::getUrl('index'),
            ],
            [
                'label' => 'Napravi prvu objavu',
                'hint' => 'Odaberi stavku iz izvora i klikni „Napravi objavu“. Hub složi sliku i tekst, a ti pregledaš i odobriš.',
                'done' => PostDraft::query()->exists(),
                'url' => ContentItemResource::getUrl('index'),
            ],
        ];
    }

    public function setupComplete(): bool
    {
        foreach ($this->setupSteps() as $step) {
            if (! $step['done']) {
                return false;
            }
        }

        return true;
    }
}
