<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

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

#[Name('hub.get_candidate')]
#[Description('Everything one candidate says: full text, labelled facts, badges, price, images. Read this before writing a caption — these fields are the only facts a post may state.')]
final class GetCandidate extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        Ability::require($request, 'mcp');

        $validated = $request->validate(['id' => ['required', 'integer']]);

        $item = ContentItem::query()->with(['brand', 'source'])->find($validated['id']);

        if ($item === null) {
            return Response::make(Response::error("Nema stavke #{$validated['id']}."));
        }

        return Response::structured(Present::item($item, full: true));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('Content item id from hub.list_candidates.')->required()];
    }
}
