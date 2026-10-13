<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Platform;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Storage;

/**
 * A YouTube variant with a vertical 9:16 Short on the public disk, the way a render leaves it.
 * Callers call Storage::fake('public') first.
 */
trait MakesYouTubeVariants
{
    protected ?Brand $youtubeBrand = null;

    protected function youtubeBrand(): Brand
    {
        return $this->youtubeBrand ??= Brand::factory()->create(['name' => 'Studentski poslovi']);
    }

    /**
     * @param  array<string, mixed>  $options  caption, settings, width, height, duration_ms, file, token_valid, assets
     */
    protected function youtubeVariant(array $options = []): PostVariant
    {
        $brand = $this->youtubeBrand();
        $tokenValid = (bool) ($options['token_valid'] ?? false);

        $account = SocialAccount::factory()->for($brand)->create([
            'platform' => Platform::YouTube,
            'name' => 'Studentski poslovi',
            'external_id' => 'UC123',
            'access_token' => $tokenValid ? 'current-token' : 'stale-token',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => $tokenValid ? now()->addHour() : now()->subMinute(),
            'meta' => ['channel_id' => 'UC123'],
        ]);

        $draft = PostDraft::factory()->for($brand)->create();

        $variant = PostVariant::query()->create([
            'post_draft_id' => $draft->id,
            'social_account_id' => $account->id,
            'platform' => Platform::YouTube,
            'caption' => (string) ($options['caption'] ?? "🍽️ Konobar/ica · 7,00 €/H\n\nHotel Adriatic, Split. Prijavi se do petka."),
            'settings' => ['format' => 'video', ...($options['settings'] ?? [])],
            'status' => 'queued',
        ]);

        $paths = ['renders/short.mp4', 'renders/second.mp4'];

        foreach (array_slice($paths, 0, (int) ($options['assets'] ?? 1)) as $position => $path) {
            $asset = MediaAsset::factory()->for($brand)->create([
                'format' => 'mp4',
                'disk' => 'public',
                'path' => $path,
                'width' => (int) ($options['width'] ?? 1080),
                'height' => (int) ($options['height'] ?? 1920),
                'duration_ms' => array_key_exists('duration_ms', $options) ? $options['duration_ms'] : 30_000,
                'bytes' => 4096,
            ]);

            if (($options['file'] ?? true) === true) {
                Storage::disk('public')->put($path, str_repeat('v', 4096));
            }

            $variant->media()->attach($asset->id, ['position' => $position]);
        }

        return $variant->load(['account', 'media']);
    }

    protected function youtubeVideoPath(): string
    {
        return Storage::disk('public')->path('renders/short.mp4');
    }
}
