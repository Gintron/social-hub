<?php

declare(strict_types=1);

namespace App\Services\SocialMedia;

use App\Models\Job;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Normalized, presentation-ready view of a job listing for social media consumers
 * (the admin copy-paste page and the Social Feed v1 endpoint read by the social hub).
 *
 * Built from the same FacebookPostFormatter helpers the admin page uses, so both
 * surfaces describe a listing identically.
 */
final readonly class JobSocialData
{
    public const SEASONAL_BADGE = 'Sezonski posao';

    /**
     * @param  list<string>  $locations
     * @param  list<string>  $perks
     * @param  list<string>  $applyMethods
     * @param  list<string>  $tags
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $slug,
        public string $company,
        public string $emoji,
        public bool $isSeasonal,
        public string $tier,
        public int $offerId,
        public array $locations,
        public string $salaryText,
        public ?string $hourRate,
        public ?string $maxHourRate,
        public array $perks,
        public string $descriptionText,
        public ?string $shortDescription,
        public array $applyMethods,
        public bool $acceptsPlatformApplies,
        public string $publicUrl,
        public ?string $imageUrl,
        public ?string $companyCoverUrl,
        public ?string $category,
        public ?CarbonInterface $activatedAt,
        public ?CarbonInterface $expiringAt,
        public ?CarbonInterface $updatedAt,
        public array $tags,
    ) {}

    public static function fromJob(Job $job, FacebookPostFormatter $formatter): self
    {
        $locations = ($job->relationLoaded('locations') ? $job->locations : $job->locations()->get())
            ->pluck('name')
            ->filter()
            ->map(fn ($name): string => (string) $name)
            ->values()
            ->all();

        $company = mb_trim((string) ($job->company_name ?: ($job->user?->name ?: '')));
        $category = $job->category?->name;

        $tags = collect([$category])
            ->merge($locations)
            ->push($job->is_seasonal ? 'sezona' : null)
            ->filter()
            ->map(fn (string $tag): string => Str::slug($tag))
            ->unique()
            ->values()
            ->all();

        $coverUrl = $job->user?->companyProfile?->getFirstMediaUrl('cover_image');

        return new self(
            id: (int) $job->id,
            title: mb_trim((string) $job->title),
            slug: $job->slug,
            company: $company,
            emoji: $formatter->categoryEmoji($job),
            isSeasonal: (bool) $job->is_seasonal,
            tier: self::tierFor((int) $job->offer_id),
            offerId: (int) $job->offer_id,
            locations: $locations,
            salaryText: $formatter->formatSalary($job),
            hourRate: self::decimalOrNull($job->hour_rate),
            maxHourRate: self::decimalOrNull($job->max_hour_rate),
            perks: $formatter->perkLabels($job),
            descriptionText: $formatter->formatDescription((string) $job->description),
            shortDescription: filled($job->short_description) ? mb_trim((string) $job->short_description) : null,
            applyMethods: $formatter->formatApplyMethods($job),
            acceptsPlatformApplies: $job->acceptsPlatformApplies(),
            publicUrl: $job->publicUrl(),
            imageUrl: filled($job->image) ? self::absoluteUrl(Storage::disk('images')->url((string) $job->image)) : null,
            companyCoverUrl: filled($coverUrl) ? self::absoluteUrl($coverUrl) : null,
            category: filled($category) ? (string) $category : null,
            activatedAt: $job->activated_at,
            expiringAt: $job->expiring_at,
            updatedAt: $job->updated_at,
            tags: $tags,
        );
    }

    /**
     * Post body: description followed by the ways to apply, as plain text.
     */
    public function bodyText(): string
    {
        $sections = [];

        if ($this->descriptionText !== '') {
            $sections[] = $this->descriptionText;
        }

        if ($this->applyMethods !== []) {
            $sections[] = "Način prijave:\n".implode("\n", $this->applyMethods);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function facts(): array
    {
        $facts = [];

        if ($this->locations !== []) {
            $facts[] = ['label' => 'LOKACIJA', 'value' => mb_strtoupper(implode(', ', $this->locations))];
        }

        $facts[] = ['label' => 'SATNICA', 'value' => $this->salaryText];

        if ($this->category !== null) {
            $facts[] = ['label' => 'KATEGORIJA', 'value' => $this->category];
        }

        return $facts;
    }

    /**
     * @return list<string>
     */
    public function badges(): array
    {
        return array_values(array_filter([
            $this->isSeasonal ? self::SEASONAL_BADGE : null,
            ...$this->perks,
        ]));
    }

    /**
     * @return array{current_cents: int|null, old_cents: null, discount_pct: null, currency: string, unit_label: string}|null
     */
    public function price(): ?array
    {
        if ($this->hourRate === null) {
            return null;
        }

        return [
            'current_cents' => (int) round((float) $this->hourRate * 100),
            'old_cents' => null,
            'discount_pct' => null,
            'currency' => 'EUR',
            'unit_label' => '€/H',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'tier' => $this->tier,
            'offer_id' => $this->offerId,
            'company' => $this->company,
            'emoji' => $this->emoji,
            'is_seasonal' => $this->isSeasonal,
            'locations' => $this->locations,
            'salary_text' => $this->salaryText,
            'hour_rate' => $this->hourRate,
            'max_hour_rate' => $this->maxHourRate,
            'perks' => $this->perks,
            'short_description' => $this->shortDescription,
            'apply_methods' => $this->applyMethods,
            'accepts_platform_applies' => $this->acceptsPlatformApplies,
            'category' => $this->category,
        ];
    }

    private static function tierFor(int $offerId): string
    {
        return match ($offerId) {
            (int) config('parameters.start_job') => 'start',
            (int) config('parameters.plus_job') => 'plus',
            (int) config('parameters.premium_job') => 'premium',
            (int) config('parameters.free_job') => 'free',
            default => 'basic',
        };
    }

    private static function decimalOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private static function absoluteUrl(string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return mb_rtrim((string) config('app.url'), '/').'/'.mb_ltrim($url, '/');
    }
}
