<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\MarkManualPosted as MarkManualPostedAction;
use App\Mcp\Support\Ability;
use App\Mcp\Support\Present;
use App\Models\PostVariant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('hub.mark_manual_posted')]
#[Description('Record that a human pasted a Facebook group post by hand. Facebook removed the groups API, so this is bookkeeping, not publishing — only call it after someone confirms they posted it.')]
final class MarkManualPosted extends Tool
{
    public function handle(Request $request, MarkManualPostedAction $action): ResponseFactory
    {
        Ability::require($request, 'mcp:publish');

        $validated = $request->validate([
            'variant_id' => ['required', 'integer'],
            'permalink' => ['nullable', 'string'],
        ]);

        $variant = PostVariant::query()->with(['account', 'media'])->find($validated['variant_id']);

        if ($variant === null) {
            return Response::make(Response::error("Nema varijante #{$validated['variant_id']}."));
        }

        try {
            $variant = $action->execute($variant, Ability::userId($request), $validated['permalink'] ?? null);
        } catch (Throwable $e) {
            return Response::make(Response::error($e->getMessage()));
        }

        return Response::structured(['marked' => true, 'variant' => Present::variant($variant->fresh(['account', 'media']))]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema->integer()->description('Variant id of a Facebook group channel.')->required(),
            'permalink' => $schema->string()->description('Link to the post someone made, if they have it.'),
        ];
    }
}
