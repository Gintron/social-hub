<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ChangeVariantFormat;
use App\Actions\ChangeVariantVoiceover;
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
#[Description('Rewrite one channel\'s caption, switch that channel off, change what it posts (image, carousel, video, link) or turn the voice-over of its video on or off. A new format or voice-over gets its media rendered for that channel only, in the background. Refuses once the post has gone out on that channel.')]
final class UpdateVariant extends Tool
{
    public function handle(Request $request, UpdateVariantAction $update, ChangeVariantFormat $changeFormat, ChangeVariantVoiceover $changeVoiceover): ResponseFactory
    {
        Ability::require($request, 'mcp:draft');

        $validated = $request->validate([
            'variant_id' => ['required', 'integer'],
            'caption' => ['nullable', 'string'],
            'enabled' => ['nullable', 'boolean'],
            'format' => ['nullable', 'string', 'in:'.implode(',', array_column(ContentFormat::cases(), 'value'))],
            'voiceover' => ['nullable', 'boolean'],
        ]);

        $variant = PostVariant::query()->with(['account', 'media', 'draft'])->find($validated['variant_id']);

        if ($variant === null) {
            return Response::make(Response::error("Nema varijante #{$validated['variant_id']}."));
        }

        try {
            if (filled($validated['format'] ?? null)) {
                $variant = $changeFormat->execute($variant, ContentFormat::from((string) $validated['format']));
            }

            if (isset($validated['voiceover'])) {
                $variant = $changeVoiceover->execute($variant, (bool) $validated['voiceover']);
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
            'format' => $schema->string()->description('image, carousel, video or link. Facebook page: all four; Instagram: image, carousel, video (Reel); TikTok: video only; Facebook group: image, carousel, link.'),
            'voiceover' => $schema->boolean()->description('true narrates this channel\'s video with the brand\'s ElevenLabs voice, false makes it music only. Video channels only; the brand needs a voice chosen.'),
        ];
    }
}
