<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Drafting\DigestBuilder;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\Platform;
use App\Models\Brand;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Throwable;

final class DraftDigest extends Command
{
    protected $signature = 'hub:draft-digest
        {brand : Brand slug}
        {--kind=deal : Content kind to collect (job, deal, article, event, generic)}
        {--count=5 : How many items to include}
        {--headline= : Override the headline; {count} becomes the number of items}
        {--tag= : Only items with this feed tag, e.g. kaufland}
        {--format=carousel : carousel, or video for a Reel on Facebook and Instagram}';

    protected $description = 'Build one digest draft (for approval) out of the brand\'s top items of a kind';

    public function handle(DigestBuilder $builder): int
    {
        $brand = Brand::query()->where('slug', (string) $this->argument('brand'))->first();

        if ($brand === null) {
            $this->error("No brand '{$this->argument('brand')}'.");

            return self::FAILURE;
        }

        $kind = ContentKind::tryFrom((string) $this->option('kind'));

        if ($kind === null) {
            $this->error("Unknown kind '{$this->option('kind')}'.");

            return self::FAILURE;
        }

        $format = ContentFormat::tryFrom((string) $this->option('format'));

        if (! in_array($format, [ContentFormat::Carousel, ContentFormat::Video], true)) {
            $this->error("Format must be carousel or video, not '{$this->option('format')}'.");

            return self::FAILURE;
        }

        $accounts = SocialAccount::query()->where('brand_id', $brand->id)->active()->get();

        if ($accounts->isEmpty()) {
            $this->error("Brand {$brand->name} has no active accounts to post to.");

            return self::FAILURE;
        }

        try {
            $draft = $builder->build(
                brand: $brand,
                kind: $kind,
                accounts: $accounts,
                count: (int) $this->option('count'),
                headline: $this->option('headline') ? (string) $this->option('headline') : null,
                tag: filled($this->option('tag')) ? mb_strtolower((string) $this->option('tag')) : null,
                // TikTok keeps its photo carousel; the format picks what Meta's channels post.
                formats: [Platform::FacebookPage->value => $format, Platform::InstagramBusiness->value => $format],
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Draft #{$draft->id}: {$draft->title}");
        $this->line('Stavke: '.$draft->contentItems->pluck('title')->implode(' · '));
        $this->line('Kanali: '.$draft->variants->map(fn ($variant): string => $variant->platform->label().' ('.$variant->format()->value.')')->implode(', '));
        $this->line('Mediji se renderiraju u pozadini (queue "render").');

        return self::SUCCESS;
    }
}
