<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialFeedItemResource;
use App\Models\Job;
use App\Services\SocialMedia\FacebookPostFormatter;
use App\Services\SocialMedia\JobSocialData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Social Feed v1 endpoint read by the social hub: active paid listings as generic items.
 *
 * Contract: social-hub/docs/social-feed-v1.md
 */
final class SocialFeedController extends Controller
{
    public const BRAND = 'studentski-poslovi';

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly FacebookPostFormatter $formatter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);

        $paidOfferIds = [
            (int) config('parameters.start_job'),
            (int) config('parameters.plus_job'),
            (int) config('parameters.premium_job'),
        ];

        $query = Job::query()
            ->active()
            ->whereIn('offer_id', $paidOfferIds)
            ->with(['locations', 'category', 'user.companyProfile.media'])
            ->orderBy('updated_at')
            ->orderBy('id');

        if (filled($validated['since'] ?? null)) {
            $query->where('updated_at', '>=', CarbonImmutable::parse((string) $validated['since'])->utc());
        }

        $page = $query->cursorPaginate($limit, ['*'], 'cursor', $validated['cursor'] ?? null);

        $items = $page->getCollection()
            ->map(fn (Job $job): array => (new SocialFeedItemResource(JobSocialData::fromJob($job, $this->formatter)))->resolve($request))
            ->values()
            ->all();

        return response()->json([
            'version' => '1',
            'brand' => self::BRAND,
            'items' => $items,
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
