<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\ApproveDraft;
use App\Actions\DispatchDraftPublishing;
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
use Throwable;

#[Name('hub.publish_draft')]
#[Description('Publish a post now, to every channel it targets. This is public and cannot be undone from here. Requires a token with the publish ability; ask the person you are working for before calling it.')]
final class PublishDraft extends Tool
{
    public function handle(Request $request, DispatchDraftPublishing $dispatch, ApproveDraft $approve): ResponseFactory
    {
        Ability::require($request, 'mcp:publish');

        $validated = $request->validate(['draft_id' => ['required', 'integer']]);

        $draft = PostDraft::query()->with(['brand', 'variants'])->find($validated['draft_id']);

        if ($draft === null) {
            return Response::make(Response::error("Nema nacrta #{$validated['draft_id']}."));
        }

        try {
            if (in_array($draft->status, [DraftStatus::Draft, DraftStatus::PendingApproval], true)) {
                $approve->execute($draft, Ability::userId($request));
            }

            $queued = $dispatch->execute($draft->refresh());
        } catch (Throwable $e) {
            return Response::make(Response::error($e->getMessage()));
        }

        return Response::structured([
            'queued_variants' => $queued,
            'note' => 'Objave idu u red; varijante za Facebook grupe čekaju da ih čovjek zalijepi i označi.',
            'draft' => Present::draft($draft->fresh(['brand', 'contentItems', 'variants.account', 'variants.media'])),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['draft_id' => $schema->integer()->description('Draft id from hub.list_drafts.')->required()];
    }
}
