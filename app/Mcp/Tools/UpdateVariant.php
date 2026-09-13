<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ChangeVariantFormat;
use App\Actions\UpdateVariant as UpdateVariantAction;
use App\Enums\ContentFormat;
use App\Mcp\Support\Ability;
use App\Mcp\Support\Present;
use App\Models\PostVariant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('hub.update_variant')]
#[Description('Rewrite one channel\'s caption, switch that channel off, or change what it posts (image, carousel, video, link). A new format gets its media rendered for that channel only, in the background. Refuses once the post has gone out on that channel.')]
final class UpdateVariant extends Tool
{
    public function handle(Request $request, UpdateVariantAction $update, ChangeVariantFormat $changeFormat): ResponseFactory
    {
        Ability::require($request, 'mcp:draft');

        $validated = $request->validate([
            'variant_id' => ['required', 'integer'],
            'caption' => ['nullable', 'string'],
            'enabled' => ['nullable', 'boolean'],
            'format' => ['nullable', 'string', 'in:'.implode(',', array_column(ContentFormat::cases(), 'value'))],
        ]);

        $variant = PostVariant::query()->with(['account', 'media', 'draft'])->find($validated['variant_id']);

        if ($variant === null) {
            return Response::make(Response::error("Nema varijante #{$validated['variant_id']}."));
        }

        try {
            if (filled($validated['format'] ?? null)) {
                $variant = $changeFormat->execute($variant, ContentFormat::from((string) $validated['format']));
            }

            $update->execute(
                $variant,
                caption: filled($validated['caption'] ?? null) ? (string) $validated['caption'] : null,
                enabled: isset($validated['enabled']) ? (bool) $validated['enabled'] : null,
            );
        } catch (InvalidArgumentException $e) {
            return Response::make(Response::error($e->getMessage()));
        }

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
            'format' => $schema->string()->description('image, carousel, video or link. Facebook page: all four; Instagram: image, carousel, video (Reel); TikTok: video, image, carousel (photo post); Facebook group: image, carousel, link.'),
        ];
    }
}
