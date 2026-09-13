<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\PrepareVariantMedia;
use App\Enums\ContentFormat;
use App\Mcp\Support\Ability;
use App\Mcp\Support\Present;
use App\Models\PostDraft;
use App\Models\PostVariant;
use App\Rendering\TemplateRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('hub.render_preview')]
#[Description('Render the post image again for every channel posting an image or carousel, optionally with a different template, and return the public URLs. Channels set to video or link are left alone (change a channel\'s format with hub.update_variant). Use it after changing a caption or to try a portrait format for Instagram.')]
final class RenderPreview extends Tool
{
    public function handle(Request $request, TemplateRegistry $templates, PrepareVariantMedia $media): ResponseFactory
    {
        Ability::require($request, 'mcp:draft');

        $validated = $request->validate([
            'draft_id' => ['required', 'integer'],
            'template' => ['nullable', 'string'],
            'append' => ['nullable', 'boolean'],
        ]);

        $draft = PostDraft::query()->with(['brand', 'contentItems', 'variants'])->find($validated['draft_id']);

        if ($draft === null) {
            return Response::make(Response::error("Nema nacrta #{$validated['draft_id']}."));
        }

        $item = $draft->contentItems->first();

        if ($item === null) {
            return Response::make(Response::error('Nacrt nema stavku iz koje bi se renderirala slika.'));
        }

        $template = filled($validated['template'] ?? null) ? (string) $validated['template'] : null;
        $imageVariants = $draft->variants->filter(fn (PostVariant $variant): bool => ! $variant->isLocked()
            && in_array($variant->format(), [ContentFormat::Image, ContentFormat::Carousel], true));

        try {
            if ($validated['append'] ?? false) {
                foreach ($imageVariants->filter(fn (PostVariant $variant): bool => $variant->format() === ContentFormat::Carousel) as $variant) {
                    $media->addSlide($variant, $template ?? $templates->defaultFor($item->kind, PrepareVariantMedia::orientation($variant->platform)), sync: true);
                }
            } else {
                $media->execute($imageVariants, fresh: true, templateKey: $template, sync: true);
            }
        } catch (Throwable $e) {
            return Response::make(Response::error($e->getMessage()));
        }

        return Response::structured([
            'template' => $template ?? 'po kanalu (kvadrat za Facebook i Instagram, uspravno za TikTok)',
            'available_templates' => array_keys($templates->optionsFor($item->kind)),
            'draft' => Present::draft($draft->fresh(['brand', 'contentItems', 'variants.account', 'variants.media'])),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->integer()->description('Draft id from hub.list_drafts.')->required(),
            'template' => $schema->string()->description('Template key, e.g. kinds/job-portrait. Empty picks one per channel.'),
            'append' => $schema->boolean()->description('true adds the image as another slide to channels set to carousel instead of replacing.'),
        ];
    }
}
