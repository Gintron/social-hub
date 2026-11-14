<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateDraft;
use App\Actions\PrepareVariantMedia;
use App\Enums\ActorType;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\DraftStatus;
use App\Enums\Platform;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Make the post of a dish by hand, as a draft for the brand's channels: the way to look at one before the automation makes them,
 * and the one to repeat when an automatic draft has to be made again. It goes through CreateDraft like every other draft, waits
 * for approval unless told otherwise, and publishes nothing.
 */
final class RecipeDraft extends Command
{
    protected $signature = 'hub:recipe-draft
        {item? : Id of a kind=recipe item (default: the newest live one with the most ingredients on sale)}
        {--brand=uselisto : Brand slug}
        {--platforms=fb_page,ig_business,tiktok : Channels, comma separated}
        {--format= : image, carousel or video on every channel that takes it (default: what each channel takes by default)}
        {--at= : Local time of the post (Y-m-d H:i, brand time zone); default is the brand\'s next posting window}
        {--approve : Make the draft approved (and scheduled) instead of waiting for approval}
        {--sync : Render here and now instead of on the render queue}';

    protected $description = 'Draft the post of a dish for the brand\'s channels (waits for approval)';

    public function handle(CreateDraft $drafts, PrepareVariantMedia $media, PostingSchedule $schedule): int
    {
        $item = filled($this->argument('item'))
            ? ContentItem::query()->with('brand')->find((int) $this->argument('item'))
            : ContentItem::query()->with('brand')->where('kind', ContentKind::Recipe->value)
                ->whereHas('brand', fn ($query) => $query->where('slug', (string) $this->option('brand')))
                ->live()->orderByDesc('priority')->orderByDesc('published_at')->first();

        if ($item === null || $item->kind !== ContentKind::Recipe) {
            $this->error('Nema stavke vrste recipe. Sinkroniziraj izvor (Social Feed v1, query.kind=recipe; hub:recipe-source ga pripremi) ili zadaj id.');

            return self::FAILURE;
        }

        $platforms = array_filter(array_map(fn (string $value): ?Platform => Platform::tryFrom(mb_trim($value)), explode(',', (string) $this->option('platforms'))));
        $accounts = SocialAccount::query()->where('brand_id', $item->brand_id)->active()
            ->whereIn('platform', array_map(fn (Platform $platform): string => $platform->value, $platforms))->get();

        if ($accounts->isEmpty()) {
            $this->error('Brend nema aktivan račun na zadanim kanalima.');

            return self::FAILURE;
        }

        $format = filled($this->option('format')) ? ContentFormat::tryFrom((string) $this->option('format')) : null;

        if (filled($this->option('format')) && $format === null) {
            $this->error('--format prima image, carousel ili video.');

            return self::FAILURE;
        }

        $brand = $item->brand;

        try {
            $at = filled($this->option('at'))
                ? CarbonImmutable::parse((string) $this->option('at'), $brand->timezone ?: (string) config('hub.brand_default_timezone', 'Europe/Zagreb'))->utc()
                : $schedule->nextSlot($brand, $schedule->queueAfter($brand, CarbonImmutable::now()));

            // A channel that does not take the format keeps its own default: TikTok and YouTube are video whatever is asked.
            $options = $format === null ? [] : $accounts->mapWithKeys(fn (SocialAccount $account): array => [
                $account->platform->value => ['format' => in_array($format, $account->platform->formats(), true) ? $format : $account->platform->defaultFormat()],
            ])->all();

            $draft = $drafts->execute(
                item: $item,
                accounts: $accounts,
                actor: ActorType::System,
                scheduledAt: $at,
                status: $this->option('approve') ? DraftStatus::Approved : DraftStatus::PendingApproval,
                render: false,
                channelOptions: $options,
            );

            $media->execute($draft->variants()->get(), sync: (bool) $this->option('sync'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Nacrt #%d (%s), termin %s', $draft->id, $draft->status->label(), $draft->scheduled_at?->setTimezone('Europe/Zagreb')->format('d.m.Y H:i') ?? '—'));

        foreach ($draft->variants()->get() as $variant) {
            $this->line(sprintf('  %-12s %-9s %s', $variant->platform->value, $variant->format()->value, (string) $variant->setting('tracking.url', $variant->link_url)));
        }

        return self::SUCCESS;
    }
}
