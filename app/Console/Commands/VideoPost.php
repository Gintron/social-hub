<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateVideoPost;
use App\Enums\DraftStatus;
use App\Models\Brand;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * Post a finished video — one made outside the hub — on a brand's channels. The words and the files are in a manifest
 * (docs/own-video.md); the draft is made like any other, waits for approval unless told otherwise, and publishes nothing by
 * itself: the scheduler does at the time given.
 */
final class VideoPost extends Command
{
    protected $signature = 'hub:video-post
        {manifest : JSON file with the video, the cover and the words of each channel (docs/own-video.md)}
        {--brand=uselisto : Brand slug}
        {--at= : Local time of the post (Y-m-d H:i, brand time zone); default is the brand\'s next posting window}
        {--approve : Make the draft approved (and scheduled) instead of waiting for approval}';

    protected $description = 'Draft a finished video made elsewhere for the brand\'s channels (waits for approval)';

    public function handle(CreateVideoPost $posts, PostingSchedule $schedule): int
    {
        $manifestPath = (string) $this->argument('manifest');

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Manifest nije ispravan JSON: {$e->getMessage()}");

            return self::FAILURE;
        }

        $brand = Brand::query()->where('slug', (string) $this->option('brand'))->first();

        if ($brand === null) {
            $this->error('Nema brenda s tom oznakom (--brand).');

            return self::FAILURE;
        }

        if (! is_array($manifest) || blank($manifest['video'] ?? null) || ! is_array($manifest['channels'] ?? null)) {
            $this->error('Manifest treba "video" i "channels".');

            return self::FAILURE;
        }

        $directory = dirname($manifestPath);
        $zone = $brand->timezone ?: (string) config('hub.brand_default_timezone', 'Europe/Zagreb');

        try {
            $at = filled($this->option('at'))
                ? CarbonImmutable::parse((string) $this->option('at'), $zone)->utc()
                : $schedule->nextSlot($brand, $schedule->queueAfter($brand, CarbonImmutable::now()));

            $draft = $posts->execute(
                brand: $brand,
                videoPath: $this->path($directory, (string) $manifest['video']),
                title: (string) ($manifest['title'] ?? ''),
                channels: $manifest['channels'],
                url: (string) ($manifest['url'] ?? $brand->site_url),
                coverPath: filled($manifest['cover'] ?? null) ? $this->path($directory, (string) $manifest['cover']) : null,
                validUntil: filled($manifest['valid_until'] ?? null) ? CarbonImmutable::parse((string) $manifest['valid_until'], $zone)->utc() : null,
                aiGenerated: (bool) ($manifest['ai_generated'] ?? true),
                scheduledAt: $at,
                status: $this->option('approve') ? DraftStatus::Approved : DraftStatus::PendingApproval,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Objava #%d (%s), termin %s', $draft->id, $draft->status->label(), $draft->scheduled_at?->setTimezone($zone)->format('d.m.Y H:i') ?? '—'));

        foreach ($draft->variants as $variant) {
            $this->line(sprintf('  %-12s %-16s komentar: %s', $variant->platform->value, $variant->account?->name, (string) $variant->setting('first_comment', '—')));
        }

        return self::SUCCESS;
    }

    private function path(string $directory, string $file): string
    {
        return str_starts_with($file, '/') ? $file : $directory.'/'.$file;
    }
}
