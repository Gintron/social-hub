<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Jobs\RenderMediaJob;
use App\Mcp\Support\Ability;
use App\Mcp\Support\Present;
use App\Models\PostDraft;
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
#[Description('Render the post image again, optionally with a different template, and return the public URLs. Use it after changing a caption or to try a portrait format for Instagram.')]
final class RenderPreview extends Tool
{
    public function handle(Request $request, TemplateRegistry $templates): ResponseFactory
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

        $template = $validated['template'] ?? $templates->defaultFor($item->kind);

        try {
            $templates->get($template);

            RenderMediaJob::dispatchSync(
                $draft->id,
                $item->id,
                $template,
                $draft->variants->pluck('id')->all(),
                [],
                ! ($validated['append'] ?? false),
            );
        } catch (Throwable $e) {
            return Response::make(Response::error($e->getMessage()));
        }

        return Response::structured([
            'template' => $template,
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
            'template' => $schema->string()->description('Template key, e.g. kinds/job-portrait.'),
            'append' => $schema->boolean()->description('true adds the image as another carousel slide instead of replacing.'),
        ];
    }
}
