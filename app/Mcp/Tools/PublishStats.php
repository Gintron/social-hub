<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\VariantStatus;
use App\Mcp\Support\Ability;
use App\Models\Brand;
use App\Models\PostVariant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('hub.publish_stats')]
#[Description('What actually went out over the last N days, per brand, channel and outcome, plus anything currently failing or waiting on a human.')]
final class PublishStats extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        Ability::require($request, 'mcp');

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'brand' => ['nullable', 'string'],
        ]);

        $days = (int) ($validated['days'] ?? 7);
        $since = now()->subDays($days);

        $variants = PostVariant::query()
            ->with(['draft.brand', 'account'])
            ->whereHas('draft', fn ($query) => $query
                ->where('updated_at', '>=', $since)
                ->when(filled($validated['brand'] ?? null), fn ($q) => $q->whereHas('brand', fn ($b) => $b->where('slug', $validated['brand']))))
            ->get();

        $byStatus = $variants->countBy(fn (PostVariant $variant): string => $variant->status->value)->all();

        $byBrandPlatform = $variants
            ->filter(fn (PostVariant $variant): bool => in_array($variant->status, [VariantStatus::Published, VariantStatus::ManualDone], true))
            ->groupBy(fn (PostVariant $variant): string => ($variant->draft?->brand?->slug ?? '?').'/'.$variant->platform->value)
            ->map->count()
            ->all();

        return Response::structured([
            'window_days' => $days,
            'brands' => Brand::query()->pluck('slug')->all(),
            'variants_by_status' => $byStatus,
            'published_by_brand_and_platform' => $byBrandPlatform,
            'needs_attention' => [
                'failed' => $variants->where('status', VariantStatus::Failed)->map(fn (PostVariant $v): array => [
                    'draft_id' => $v->post_draft_id,
                    'platform' => $v->platform->value,
                    'error' => $v->error_code.': '.$v->error_message,
                ])->values()->all(),
                'waiting_for_manual_post' => $variants->where('status', VariantStatus::ManualPending)->map(fn (PostVariant $v): array => [
                    'draft_id' => $v->post_draft_id,
                    'account' => $v->account?->name,
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Window in days, 1-90. Default 7.'),
            'brand' => $schema->string()->description('Restrict to one brand slug.'),
        ];
    }
}
