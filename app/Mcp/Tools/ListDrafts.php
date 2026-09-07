<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\DraftStatus;
use App\Mcp\Support\Ability;
use App\Mcp\Support\Present;
use App\Models\PostDraft;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('hub.list_drafts')]
#[Description('Posts in the hub with their per-channel captions, images and publishing state. Filter by brand or status; defaults to the ones waiting for approval.')]
final class ListDrafts extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        Ability::require($request, 'mcp');

        $validated = $request->validate([
            'brand' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $drafts = PostDraft::query()
            ->with(['brand', 'contentItems', 'variants.account', 'variants.media'])
            ->when(filled($validated['brand'] ?? null), fn ($query) => $query->whereHas('brand', fn ($q) => $q->where('slug', $validated['brand'])))
            ->where('status', $validated['status'] ?? DraftStatus::PendingApproval->value)
            ->orderByDesc('updated_at')
            ->limit((int) ($validated['limit'] ?? 10))
            ->get();

        return Response::structured([
            'count' => $drafts->count(),
            'drafts' => $drafts->map(fn (PostDraft $draft): array => Present::draft($draft))->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand' => $schema->string()->description('Brand slug.'),
            'status' => $schema->string()->enum(DraftStatus::class)->description('Defaults to pending_approval.'),
            'limit' => $schema->integer()->description('How many to return, 1-50. Default 10.'),
        ];
    }
}
