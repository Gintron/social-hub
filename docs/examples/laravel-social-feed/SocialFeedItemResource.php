<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\SocialMedia\JobSocialData;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Social Feed v1 item (see social-hub/docs/social-feed-v1.md).
 *
 * @property JobSocialData $resource
 */
final class SocialFeedItemResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $job = $this->resource;

        $images = [];

        if ($job->imageUrl !== null) {
            $images[] = ['url' => $job->imageUrl, 'role' => 'primary', 'alt' => $job->title];
        }

        if ($job->companyCoverUrl !== null) {
            $images[] = ['url' => $job->companyCoverUrl, 'role' => 'gallery', 'alt' => $job->company !== '' ? $job->company : null];
        }

        return [
            'id' => 'job:'.$job->id,
            'kind' => 'job',
            'title' => $job->title,
            'subtitle' => $job->company !== '' ? $job->company : null,
            'body_text' => $job->bodyText(),
            'facts' => $job->facts(),
            'badges' => $job->badges(),
            'price' => $job->price(),
            'cta' => [
                'label' => $job->acceptsPlatformApplies ? 'Prijavi se' : 'Detalji o poslu',
                'url' => $job->publicUrl,
            ],
            'url' => $job->publicUrl,
            'images' => $images,
            'priority' => $job->offerId,
            'tags' => $job->tags,
            'published_at' => self::iso($job->activatedAt),
            'expires_at' => self::iso($job->expiringAt),
            'updated_at' => self::iso($job->updatedAt) ?? now()->toIso8601ZuluString(),
            'raw' => $job->toArray(),
        ];
    }

    private static function iso(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601ZuluString();
    }
}
