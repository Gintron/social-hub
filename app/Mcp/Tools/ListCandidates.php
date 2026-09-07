<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\ContentKind;
use App\Mcp\Support\Ability;
use App\Mcp\Support\Present;
use App\Models\ContentItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('hub.list_candidates')]
#[Description('Content pulled from the brands\' sites that could become a post: job listings, deals, articles. Defaults to items that are still live and have no post yet, highest priority first.')]
final class ListCandidates extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        Ability::require($request, 'mcp');

        $validated = $request->validate([
            'brand' => ['nullable', 'string'],
            'kind' => ['nullable', 'string'],
            'include_drafted' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $items = ContentItem::query()
            ->with(['brand', 'source'])
            ->when(filled($validated['brand'] ?? null), fn ($query) => $query->whereHas('brand', fn ($q) => $q->where('slug', $validated['brand'])))
            ->when(filled($validated['kind'] ?? null), fn ($query) => $query->where('kind', $validated['kind']))
            ->live()
            ->when(! ($validated['include_drafted'] ?? false), fn ($query) => $query->notDrafted())
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->limit((int) ($validated['limit'] ?? 10))
            ->get();

        return Response::structured([
            'count' => $items->count(),
            'items' => $items->map(fn (ContentItem $item): array => Present::item($item))->all(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'brand' => $schema->string()->description('Brand slug from hub.list_sources.'),
            'kind' => $schema->string()->enum(ContentKind::class)->description('Restrict to one content kind.'),
            'include_drafted' => $schema->boolean()->description('Include items that already have a post. Default false.'),
            'limit' => $schema->integer()->description('How many to return, 1-50. Default 10.'),
        ];
    }
}
