<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\VariantStatus;
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

#[Name('hub.update_variant')]
#[Description('Rewrite one channel\'s caption, or switch that channel off for this post. Refuses once the post has gone out on that channel.')]
final class UpdateVariant extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        Ability::require($request, 'mcp:draft');

        $validated = $request->validate([
            'variant_id' => ['required', 'integer'],
            'caption' => ['nullable', 'string'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $variant = PostVariant::query()->with(['account', 'media', 'draft'])->find($validated['variant_id']);

        if ($variant === null) {
            return Response::make(Response::error("Nema varijante #{$validated['variant_id']}."));
        }

        if (in_array($variant->status, [VariantStatus::Published, VariantStatus::ManualDone, VariantStatus::Publishing], true)) {
            return Response::make(Response::error("Varijanta je {$variant->status->label()} i više se ne mijenja."));
        }

        if (filled($validated['caption'] ?? null)) {
            $variant->caption = (string) $validated['caption'];
        }

        if (array_key_exists('enabled', $validated) && $validated['enabled'] !== null) {
            $variant->enabled = (bool) $validated['enabled'];
        }

        $variant->save();

        return Response::structured(['updated' => true, 'variant' => Present::variant($variant->fresh(['account', 'media']))]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema->integer()->description('Variant id from hub.list_drafts.')->required(),
            'caption' => $schema->string()->description('Replacement text for this channel.'),
            'enabled' => $schema->boolean()->description('false parks this channel so publishing skips it.'),
        ];
    }
}
